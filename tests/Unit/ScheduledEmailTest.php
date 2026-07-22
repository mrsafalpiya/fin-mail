<?php

declare(strict_types=1);

use FinityLabs\FinMail\Enums\ScheduledEmailStatus;
use FinityLabs\FinMail\Mail\TemplateMail;
use FinityLabs\FinMail\Models\EmailTemplate;
use FinityLabs\FinMail\Models\ScheduledEmail;
use FinityLabs\FinMail\Settings\BrandingSettings;
use FinityLabs\FinMail\Settings\GeneralSettings;
use FinityLabs\FinMail\Settings\LoggingSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    BrandingSettings::fake(BrandingSettings::defaults(), loadMissingValues: false);
    GeneralSettings::fake(GeneralSettings::defaults(), loadMissingValues: false);
    LoggingSettings::fake(['enabled' => false] + LoggingSettings::defaults(), loadMissingValues: false);

    EmailTemplate::create([
        'key' => 'scheduled-test',
        'name' => ['en' => 'Scheduled Test'],
        'category' => 'transactional',
        'subject' => ['en' => 'Hello'],
        'body' => ['en' => '<p>Body</p>'],
        'is_active' => true,
    ]);

    Mail::fake();
});

/**
 * @param  list<string>  $to
 */
function makeScheduled(array $to, Carbon|string $when, ?string $sendMode = null): ScheduledEmail
{
    return ScheduledEmail::create([
        'email_template_id' => EmailTemplate::where('key', 'scheduled-test')->value('id'),
        'from_address' => app(GeneralSettings::class)->default_from_address,
        'to' => $to,
        'subject' => 'Hello',
        'payload' => [
            'template_key' => 'scheduled-test',
            'locale' => 'en',
            'from' => app(GeneralSettings::class)->default_from_address,
            'to' => $to,
            'cc' => [],
            'bcc' => [],
            'subject' => 'Hello',
            'body' => '<p>Body</p>',
        ],
        'send_mode' => count($to) > 1 ? $sendMode : null,
        'scheduled_at' => $when,
        'status' => ScheduledEmailStatus::Pending,
    ]);
}

it('dispatches a due scheduled email and marks it sent', function () {
    $email = makeScheduled(['a@example.com'], now()->subMinute());

    $this->artisan('fin-mail:send-scheduled')->assertSuccessful();

    Mail::assertQueued(TemplateMail::class, 1);
    expect($email->fresh()->status)->toBe(ScheduledEmailStatus::Sent)
        ->and($email->fresh()->sent_at)->not->toBeNull();
});

it('leaves a future scheduled email untouched', function () {
    $email = makeScheduled(['a@example.com'], now()->addHour());

    $this->artisan('fin-mail:send-scheduled')->assertSuccessful();

    Mail::assertNothingQueued();
    expect($email->fresh()->status)->toBe(ScheduledEmailStatus::Pending);
});

it('expands an individual-mode batch into one email per recipient', function () {
    makeScheduled(['a@example.com', 'b@example.com'], now()->subMinute(), 'individual');

    $this->artisan('fin-mail:send-scheduled')->assertSuccessful();

    Mail::assertQueued(TemplateMail::class, 2);
    Mail::assertQueued(TemplateMail::class, fn (TemplateMail $mail): bool => $mail->hasTo('a@example.com') && ! $mail->hasTo('b@example.com'));
    Mail::assertQueued(TemplateMail::class, fn (TemplateMail $mail): bool => $mail->hasTo('b@example.com') && ! $mail->hasTo('a@example.com'));
});

it('sends a combined-mode batch as a single message', function () {
    makeScheduled(['a@example.com', 'b@example.com'], now()->subMinute(), 'combined');

    $this->artisan('fin-mail:send-scheduled')->assertSuccessful();

    Mail::assertQueued(TemplateMail::class, 1);
    Mail::assertQueued(TemplateMail::class, fn (TemplateMail $mail): bool => $mail->hasTo('a@example.com') && $mail->hasTo('b@example.com'));
});

it('does not resend on a second run', function () {
    $email = makeScheduled(['a@example.com'], now()->subMinute());

    $this->artisan('fin-mail:send-scheduled')->assertSuccessful();
    $this->artisan('fin-mail:send-scheduled')->assertSuccessful();

    Mail::assertQueued(TemplateMail::class, 1);
    expect($email->fresh()->status)->toBe(ScheduledEmailStatus::Sent);
});

it('marks a scheduled email failed when its template is missing', function () {
    $email = makeScheduled(['a@example.com'], now()->subMinute());
    $email->update([
        'payload' => ['locale' => 'en', 'to' => ['a@example.com'], 'subject' => 'Hello', 'body' => '<p>Body</p>'],
    ]);

    $this->artisan('fin-mail:send-scheduled')->assertSuccessful();

    Mail::assertNothingQueued();
    expect($email->fresh()->status)->toBe(ScheduledEmailStatus::Failed)
        ->and($email->fresh()->metadata['error'] ?? null)->not->toBeNull();
});

it('does not dispatch a cancelled scheduled email', function () {
    $email = makeScheduled(['a@example.com'], now()->subMinute());
    $email->markAsCancelled();

    $this->artisan('fin-mail:send-scheduled')->assertSuccessful();

    Mail::assertNothingQueued();
    expect($email->fresh()->status)->toBe(ScheduledEmailStatus::Cancelled);
});
