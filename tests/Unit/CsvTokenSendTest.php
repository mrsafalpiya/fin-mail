<?php

declare(strict_types=1);

use FinityLabs\FinMail\Actions\EmailSender;
use FinityLabs\FinMail\Helpers\RecipientGrouper;
use FinityLabs\FinMail\Mail\TemplateMail;
use FinityLabs\FinMail\Models\EmailTemplate;
use FinityLabs\FinMail\Settings\BrandingSettings;
use FinityLabs\FinMail\Settings\GeneralSettings;
use FinityLabs\FinMail\Settings\LoggingSettings;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    BrandingSettings::fake(BrandingSettings::defaults(), loadMissingValues: false);
    GeneralSettings::fake(GeneralSettings::defaults(), loadMissingValues: false);
    LoggingSettings::fake(['enabled' => false] + LoggingSettings::defaults(), loadMissingValues: false);

    EmailTemplate::create([
        'key' => 'csv-token-test',
        'name' => ['en' => 'CSV Token Test'],
        'category' => 'transactional',
        'subject' => ['en' => 'Hello'],
        'body' => ['en' => '<p>Hi</p>'],
        'token_schema' => [
            ['token' => 'user.name', 'description' => 'Name'],
            ['token' => 'invoice.total', 'description' => 'Total'],
            ['token' => 'config.app.name', 'description' => 'App'],
        ],
        'is_active' => true,
    ]);
});

/**
 * @param  array<string, string>  $tokenValues
 */
function csvMail(array $tokenValues, string $body = '<p>Hi {{ user.name }}</p>', string $subject = 'Hello'): TemplateMail
{
    $mail = TemplateMail::make('csv-token-test', 'en');

    $sender = new EmailSender(
        data: [
            'template_key' => 'csv-token-test',
            'locale' => 'en',
            'to' => ['a@example.com'],
            'subject' => $subject,
            'body' => $body,
            'token_values' => $tokenValues,
        ],
        templateKey: 'csv-token-test',
        notify: false,
    );

    Mail::fake();
    $sender->send();

    $sent = null;
    Mail::assertQueued(TemplateMail::class, function (TemplateMail $queued) use (&$sent): bool {
        $sent = $queued;

        return true;
    });

    return $sent ?? $mail;
}

it('excludes config tokens from the per-recipient token list', function () {
    expect(EmailTemplate::findByKey('csv-token-test')->csvTokens())
        ->toBe(['user.name', 'invoice.total']);
});

it('renders each recipient token value into the body', function () {
    expect(csvMail(['user.name' => 'Alice'])->content()->with['body'])
        ->toContain('Hi Alice');
});

it('replaces tokens in the composed subject line', function () {
    expect(csvMail(['user.name' => 'Alice'], subject: 'Invoice for {{ user.name }}')->envelope()->subject)
        ->toBe('Invoice for Alice');
});

it('escapes token values in the body but not in the subject', function () {
    $mail = csvMail(
        ['user.name' => 'Smith & Sons <b>'],
        subject: 'Hello {{ user.name }}',
    );

    expect($mail->content()->with['body'])->toContain('Smith &amp; Sons &lt;b&gt;')
        ->and($mail->envelope()->subject)->toBe('Hello Smith & Sons <b>');
});

it('renders a missing token value as nothing rather than leaking the token', function () {
    $body = csvMail(['invoice.total' => '$40.00'])->content()->with['body'];

    expect($body)->toContain('<p>Hi </p>')
        ->and($body)->not->toContain('{{');
});

it('prefers the body fallback over blanking a missing value', function () {
    expect(csvMail([], body: "<p>Hi {{ user.name | 'Customer' }}</p>")->content()->with['body'])
        ->toContain('Hi Customer');
});

it('leaves undeclared tokens alone when a value is missing', function () {
    expect(csvMail(['user.name' => 'Alice'], body: '<p>{{ mystery.value }}</p>')->content()->with['body'])
        ->toContain('{{ mystery.value }}');
});

it('replaces tokens in an overridden preheader', function () {
    $sender = new EmailSender(
        data: [
            'template_key' => 'csv-token-test',
            'locale' => 'en',
            'to' => ['a@example.com'],
            'subject' => 'Hello',
            'preheader' => 'A note for {{ user.name }}',
            'body' => '<p>Hi</p>',
            'token_values' => ['user.name' => 'Alice'],
        ],
        templateKey: 'csv-token-test',
        notify: false,
    );

    Mail::fake();
    $sender->send();

    Mail::assertQueued(TemplateMail::class, fn (TemplateMail $mail): bool => $mail->content()->with['preheader'] === 'A note for Alice');
});

describe('batch expansion', function () {
    $rows = [
        ['email' => 'alice@example.com', 'tokens' => ['user.name' => 'Alice']],
        ['email' => 'bob@example.com', 'tokens' => ['user.name' => 'Bob']],
    ];

    it('turns every CSV row into its own email with its own token values', function () use ($rows) {
        expect(RecipientGrouper::sendGroups(['csv_rows' => $rows], null))->toBe([
            ['to' => ['alice@example.com'], 'token_values' => ['user.name' => 'Alice']],
            ['to' => ['bob@example.com'], 'token_values' => ['user.name' => 'Bob']],
        ]);
    });

    it('ignores the send mode for a CSV batch', function () use ($rows) {
        expect(RecipientGrouper::sendGroups(['csv_rows' => $rows], 'combined'))
            ->toHaveCount(2);
    });

    it('falls back to send-mode grouping without a CSV', function () {
        expect(RecipientGrouper::sendGroups(['to' => ['a@example.com', 'b@example.com']], 'individual'))
            ->toBe([['to' => ['a@example.com']], ['to' => ['b@example.com']]])
            ->and(RecipientGrouper::sendGroups(['to' => ['a@example.com', 'b@example.com']], 'combined'))
            ->toBe([['to' => ['a@example.com', 'b@example.com']]]);
    });

    it('sends one message per row when a batch goes out', function () use ($rows) {
        Mail::fake();

        foreach (RecipientGrouper::sendGroups(['csv_rows' => $rows], null) as $group) {
            (new EmailSender(
                data: array_merge([
                    'template_key' => 'csv-token-test',
                    'locale' => 'en',
                    'subject' => 'Hello {{ user.name }}',
                    'body' => '<p>Hi {{ user.name }}</p>',
                ], $group),
                templateKey: 'csv-token-test',
                notify: false,
            ))->send();
        }

        Mail::assertQueued(TemplateMail::class, 2);
        Mail::assertQueued(TemplateMail::class, fn (TemplateMail $mail): bool => $mail->hasTo('alice@example.com')
            && $mail->envelope()->subject === 'Hello Alice'
            && str_contains($mail->content()->with['body'], 'Hi Alice'));
        Mail::assertQueued(TemplateMail::class, fn (TemplateMail $mail): bool => $mail->hasTo('bob@example.com')
            && $mail->envelope()->subject === 'Hello Bob'
            && str_contains($mail->content()->with['body'], 'Hi Bob'));
    });
});
