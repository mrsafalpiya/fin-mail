<?php

declare(strict_types=1);

use FinityLabs\FinMail\Helpers\UtmComposer;
use FinityLabs\FinMail\Models\EmailTemplate;
use Illuminate\Database\Eloquent\Model;

beforeEach(function () {
    config()->set('fin-mail.utm.enabled', true);
});

/**
 * @param  array<string, mixed>  $extra
 */
function makeTemplate(string $body, array $extra = []): EmailTemplate
{
    return EmailTemplate::create(array_merge([
        'key' => 'utm-'.uniqid(),
        'name' => ['en' => 'UTM'],
        'category' => 'transactional',
        'subject' => ['en' => 'Test'],
        'body' => ['en' => $body],
        'is_active' => true,
    ], $extra));
}

function buttonBlockHtml(array $config): string
{
    $encoded = htmlspecialchars((string) json_encode($config), ENT_QUOTES);

    return '<div data-type="customBlock" data-id="emailButton" data-config="'.$encoded.'"></div>';
}

it('exposes template-level UTM defaults, omitting empties', function () {
    $template = makeTemplate('<p>Hi</p>', [
        'utm_source' => 'newsletter',
        'utm_medium' => '',
        'utm_campaign' => 'summer',
    ]);

    expect($template->utmDefaults())->toBe([
        'utm_source' => 'newsletter',
        'utm_campaign' => 'summer',
    ]);
});

it('returns no UTM defaults when the feature is disabled', function () {
    config()->set('fin-mail.utm.enabled', false);

    $template = makeTemplate('<p>Hi</p>', ['utm_source' => 'newsletter']);

    expect($template->utmDefaults())->toBe([]);
});

it('composes UTM onto an opted-in inline link at render', function () {
    $href = UtmComposer::embedLinkMarkers('https://example.com', 'cta', null);
    $body = '<p><a href="'.htmlspecialchars($href, ENT_QUOTES).'">Go</a></p>';

    $template = makeTemplate($body, ['utm_source' => 'newsletter', 'utm_campaign' => 'launch']);

    $out = html_entity_decode($template->render()['body'], ENT_QUOTES);

    expect($out)->toContain('utm_source=newsletter')
        ->and($out)->toContain('utm_campaign=launch')
        ->and($out)->toContain('utm_content=cta')
        ->and($out)->not->toContain('fm_utm');
});

it('leaves opted-out inline links clean at render', function () {
    $body = '<p><a href="https://example.com">Go</a></p>';

    $template = makeTemplate($body, ['utm_source' => 'newsletter']);

    expect($template->render()['body'])->toContain('<a href="https://example.com">')
        ->and($template->render()['body'])->not->toContain('utm_source');
});

it('composes UTM onto an opted-in button block at render', function () {
    $body = buttonBlockHtml([
        'label' => 'Buy',
        'url' => 'https://shop.com',
        'align' => 'center',
        'use_utm' => true,
        'utm_content' => 'hero',
    ]);

    $template = makeTemplate($body, ['utm_source' => 'newsletter', 'utm_medium' => 'email']);

    $out = html_entity_decode($template->render()['body'], ENT_QUOTES);

    expect($out)->toContain('utm_source=newsletter')
        ->and($out)->toContain('utm_medium=email')
        ->and($out)->toContain('utm_content=hero');
});

it('leaves a button block without the UTM toggle clean at render', function () {
    $body = buttonBlockHtml([
        'label' => 'Buy',
        'url' => 'https://shop.com',
        'align' => 'center',
    ]);

    $template = makeTemplate($body, ['utm_source' => 'newsletter']);

    expect($template->render()['body'])->toContain('href="https://shop.com"')
        ->and($template->render()['body'])->not->toContain('utm_source');
});

it('does not compose UTM when the feature is disabled', function () {
    config()->set('fin-mail.utm.enabled', false);

    $href = UtmComposer::embedLinkMarkers('https://example.com', 'cta', null);
    $body = '<p><a href="'.htmlspecialchars($href, ENT_QUOTES).'">Go</a></p>'
        .buttonBlockHtml(['label' => 'Buy', 'url' => 'https://shop.com', 'use_utm' => true, 'utm_content' => 'hero']);

    $template = makeTemplate($body, ['utm_source' => 'newsletter']);

    $out = html_entity_decode($template->render()['body'], ENT_QUOTES);

    expect($out)->not->toContain('utm_source')
        ->and($out)->not->toContain('utm_content=hero')
        ->and($out)->not->toContain('fm_utm');
});

it('resolves and url-encodes tokens inside UTM values at render', function () {
    $href = UtmComposer::embedLinkMarkers('https://example.com', null, null);
    $body = '<p><a href="'.htmlspecialchars($href, ENT_QUOTES).'">Go</a></p>';

    $template = makeTemplate($body, ['utm_campaign' => '{{ campaign.slug }}']);

    $campaign = new class extends Model
    {
        protected $attributes = ['slug' => 'spring sale'];
    };

    $out = html_entity_decode($template->render(['campaign' => $campaign])['body'], ENT_QUOTES);

    expect($out)->toContain('utm_campaign=spring%20sale')
        ->and($out)->not->toContain('{{ campaign.slug }}')
        ->and($out)->not->toContain('__FM_UTM_ENC');
});

it('does not compose UTM when rendering without blocks for editing', function () {
    $href = UtmComposer::embedLinkMarkers('https://example.com', 'cta', null);
    $body = '<p><a href="'.htmlspecialchars($href, ENT_QUOTES).'">Go</a></p>';

    $template = makeTemplate($body, ['utm_source' => 'newsletter']);

    $out = $template->render([], null, renderBlocks: false)['body'];

    // Markers are preserved so the link modal can round-trip them.
    expect($out)->toContain('fm_utm')
        ->and($out)->not->toContain('utm_source=newsletter');
});
