<?php

declare(strict_types=1);

use FinityLabs\FinMail\Enums\ScheduledEmailStatus;
use FinityLabs\FinMail\Mail\TemplateMail;
use FinityLabs\FinMail\Models\EmailTemplate;
use FinityLabs\FinMail\Models\ScheduledEmail;
use FinityLabs\FinMail\Resources\ScheduledEmailResource\Pages\EditScheduledEmail;
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
        'key' => 'edit-schedule-test',
        'name' => ['en' => 'Edit Schedule Test'],
        'category' => 'transactional',
        'subject' => ['en' => 'Hello'],
        'body' => ['en' => '<p>Body</p>'],
        'is_active' => true,
    ]);

    EmailTemplate::create([
        'key' => 'edit-schedule-csv-test',
        'name' => ['en' => 'Edit Schedule CSV Test'],
        'category' => 'transactional',
        'subject' => ['en' => 'Hello'],
        'body' => ['en' => '<p>Hi {{ user.name }}</p>'],
        'token_schema' => [
            ['token' => 'user.name', 'description' => 'Name'],
            ['token' => 'invoice.total', 'description' => 'Total'],
        ],
        'is_active' => true,
    ]);

    Mail::fake();
});

/**
 * A pending schedule of the kind the compose page creates.
 *
 * @param  list<string>  $to
 * @param  array<string, mixed>  $payload  Merged over the baseline compose payload.
 */
function makeEditableSchedule(
    array $to = ['a@example.com'],
    ?string $sendMode = null,
    string $templateKey = 'edit-schedule-test',
    array $payload = [],
): ScheduledEmail {
    return ScheduledEmail::create([
        'email_template_id' => EmailTemplate::where('key', $templateKey)->value('id'),
        'from_address' => app(GeneralSettings::class)->default_from_address,
        'to' => $to,
        'subject' => 'Hello',
        'payload' => array_merge([
            'template_key' => $templateKey,
            'locale' => 'en',
            'from' => app(GeneralSettings::class)->default_from_address,
            'to' => $to,
            'cc' => [],
            'bcc' => [],
            'subject' => 'Hello',
            'preheader' => '',
            'body' => '<p>Body</p>',
        ], $payload),
        'send_mode' => count($to) > 1 ? $sendMode : null,
        'scheduled_at' => now()->addHour(),
        'status' => ScheduledEmailStatus::Pending,
        'sent_by' => 1,
    ]);
}

/**
 * The edit page without booting Livewire: everything asserted here reshapes
 * state off the record, so the page only needs the record it is bound to.
 */
function schedulePage(ScheduledEmail $record): EditScheduledEmail
{
    $page = (new ReflectionClass(EditScheduledEmail::class))->newInstanceWithoutConstructor();
    $page->record = $record;

    return $page;
}

/**
 * @param  array<int, mixed>  $arguments
 */
function callOnPage(object $page, string $method, array $arguments = []): mixed
{
    $reflected = new ReflectionMethod($page, $method);
    $reflected->setAccessible(true);

    return $reflected->invoke($page, ...$arguments);
}

describe('updateIfPending', function () {
    it('applies an edit to a pending schedule', function () {
        $schedule = makeEditableSchedule();
        $when = now()->addDay()->startOfMinute();

        $applied = $schedule->updateIfPending([
            'subject' => 'Rewritten',
            'to' => ['b@example.com'],
            'scheduled_at' => $when,
        ]);

        expect($applied)->toBeTrue()
            ->and($schedule->fresh()->subject)->toBe('Rewritten')
            ->and($schedule->fresh()->to)->toBe(['b@example.com'])
            ->and($schedule->fresh()->scheduled_at->format('Y-m-d H:i'))->toBe($when->format('Y-m-d H:i'))
            ->and($schedule->fresh()->status)->toBe(ScheduledEmailStatus::Pending);
    });

    it('refuses an edit once the sender has claimed the row', function () {
        $schedule = makeEditableSchedule();

        // What the cron does the instant before this edit lands.
        ScheduledEmail::query()->whereKey($schedule->getKey())->update([
            'status' => ScheduledEmailStatus::Sent,
        ]);

        $applied = $schedule->updateIfPending(['subject' => 'Too late']);

        expect($applied)->toBeFalse()
            ->and($schedule->fresh()->subject)->toBe('Hello')
            ->and($schedule->fresh()->status)->toBe(ScheduledEmailStatus::Sent);
    });

    it('leaves the model matching the row after a refused edit', function () {
        $schedule = makeEditableSchedule();

        ScheduledEmail::query()->whereKey($schedule->getKey())->update([
            'status' => ScheduledEmailStatus::Sent,
        ]);

        $schedule->updateIfPending(['subject' => 'Too late']);

        // The unsaved edit must not survive on the in-memory model either.
        expect($schedule->subject)->toBe('Hello');
    });

    it('refuses an edit to a cancelled or failed schedule', function (ScheduledEmailStatus $status) {
        $schedule = makeEditableSchedule();
        ScheduledEmail::query()->whereKey($schedule->getKey())->update(['status' => $status]);

        expect($schedule->updateIfPending(['subject' => 'Nope']))->toBeFalse()
            ->and($schedule->fresh()->subject)->toBe('Hello');
    })->with([
        ScheduledEmailStatus::Cancelled,
        ScheduledEmailStatus::Failed,
    ]);

    it('preserves who scheduled the email', function () {
        $schedule = makeEditableSchedule();

        $schedule->updateIfPending([
            'subject' => 'Rewritten',
            'metadata' => ['last_edited_by' => 2],
        ]);

        expect($schedule->fresh()->sent_by)->toBe(1)
            ->and($schedule->fresh()->metadata['last_edited_by'])->toBe(2);
    });
});

describe('schedule attributes', function () {
    it('drops the send mode when the recipients come down to one', function () {
        $schedule = makeEditableSchedule(['a@example.com', 'b@example.com'], 'individual');

        $attributes = callOnPage(schedulePage($schedule), 'scheduleAttributes', [
            ['to' => ['a@example.com'], 'from' => 'x@example.com', 'subject' => 'Hello'],
            ['send_mode' => 'individual', 'scheduled_at' => now()->addHour()],
        ]);

        expect($attributes['send_mode'])->toBeNull()
            ->and($attributes['to'])->toBe(['a@example.com']);
    });

    it('keeps the chosen send mode for several recipients', function () {
        $schedule = makeEditableSchedule(['a@example.com', 'b@example.com'], 'individual');

        $attributes = callOnPage(schedulePage($schedule), 'scheduleAttributes', [
            ['to' => ['a@example.com', 'b@example.com'], 'subject' => 'Hello'],
            ['send_mode' => 'combined', 'scheduled_at' => now()->addHour()],
        ]);

        expect($attributes['send_mode'])->toBe('combined');
    });

    it('forces individual delivery for a CSV batch whatever the modal said', function () {
        $schedule = makeEditableSchedule(
            ['a@example.com', 'b@example.com'],
            templateKey: 'edit-schedule-csv-test',
        );

        $attributes = callOnPage(schedulePage($schedule), 'scheduleAttributes', [
            ['to' => ['a@example.com', 'b@example.com'], 'subject' => 'Hello'],
            ['send_mode' => 'combined', 'scheduled_at' => now()->addHour()],
        ]);

        expect($attributes['send_mode'])->toBe('individual');
    });

    it('stamps the template key onto the payload it stores', function () {
        $schedule = makeEditableSchedule();

        $attributes = callOnPage(schedulePage($schedule), 'scheduleAttributes', [
            ['to' => ['a@example.com'], 'subject' => 'Hello', 'body' => '<p>New</p>'],
            ['scheduled_at' => now()->addHour()],
        ]);

        expect($attributes['payload']['template_key'])->toBe('edit-schedule-test')
            ->and($attributes['payload']['body'])->toBe('<p>New</p>');
    });
});

describe('CSV rehydration', function () {
    it('rebuilds the parsed recipient state from the stored rows', function () {
        $schedule = makeEditableSchedule(templateKey: 'edit-schedule-csv-test');

        $state = callOnPage(schedulePage($schedule), 'csvStateFromRows', [[
            ['email' => 'a@example.com', 'tokens' => ['user.name' => 'Alice']],
            ['email' => 'b@example.com', 'tokens' => ['user.name' => 'Bob']],
        ]]);

        expect($state['rows'])->toHaveCount(2)
            ->and($state['rows'][0]['email'])->toBe('a@example.com')
            // Only the token a column actually supplied is reported as mapped.
            ->and($state['mapped_tokens'])->toBe(['user.name'])
            ->and($state['error'])->toBeNull();
    });

    it('reports no mapped tokens for rows that carry none', function () {
        $schedule = makeEditableSchedule(templateKey: 'edit-schedule-csv-test');

        $state = callOnPage(schedulePage($schedule), 'csvStateFromRows', [[
            ['email' => 'a@example.com', 'tokens' => []],
        ]]);

        expect($state['mapped_tokens'])->toBe([]);
    });
});

describe('the edited schedule as the sender sees it', function () {
    it('sends the rewritten content when the schedule fires', function () {
        $schedule = makeEditableSchedule(['a@example.com']);

        $attributes = callOnPage(schedulePage($schedule), 'scheduleAttributes', [
            [
                'template_key' => 'edit-schedule-test',
                'locale' => 'en',
                'to' => ['b@example.com'],
                'cc' => [],
                'bcc' => [],
                'subject' => 'Rewritten',
                'body' => '<p>Rewritten</p>',
            ],
            ['scheduled_at' => now()->subMinute()],
        ]);

        expect($schedule->updateIfPending($attributes))->toBeTrue();

        $this->artisan('fin-mail:send-scheduled')->assertSuccessful();

        Mail::assertQueued(TemplateMail::class, 1);
        Mail::assertQueued(
            TemplateMail::class,
            fn (TemplateMail $mail): bool => $mail->hasTo('b@example.com') && ! $mail->hasTo('a@example.com'),
        );
    });

    it('expands an edited CSV batch into one email per stored row', function () {
        $schedule = makeEditableSchedule(
            ['a@example.com'],
            templateKey: 'edit-schedule-csv-test',
        );

        $attributes = callOnPage(schedulePage($schedule), 'scheduleAttributes', [
            [
                'template_key' => 'edit-schedule-csv-test',
                'locale' => 'en',
                'subject' => 'Hello',
                'body' => '<p>Hi {{ user.name }}</p>',
                'to' => ['a@example.com', 'b@example.com'],
                'csv_rows' => [
                    ['email' => 'a@example.com', 'tokens' => ['user.name' => 'Alice']],
                    ['email' => 'b@example.com', 'tokens' => ['user.name' => 'Bob']],
                ],
            ],
            ['scheduled_at' => now()->subMinute()],
        ]);

        expect($schedule->updateIfPending($attributes))->toBeTrue();

        $this->artisan('fin-mail:send-scheduled')->assertSuccessful();

        Mail::assertQueued(TemplateMail::class, 2);
    });

    it('does not fire twice when an edit lands before the send time', function () {
        $schedule = makeEditableSchedule(['a@example.com']);

        $schedule->updateIfPending([
            'subject' => 'Rewritten',
            'scheduled_at' => Carbon::now()->addHour(),
        ]);

        $this->artisan('fin-mail:send-scheduled')->assertSuccessful();

        Mail::assertNothingQueued();
        expect($schedule->fresh()->status)->toBe(ScheduledEmailStatus::Pending);
    });
});
