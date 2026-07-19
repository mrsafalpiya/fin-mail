<?php

declare(strict_types=1);

use FinityLabs\FinMail\Helpers\UtmComposer;

beforeEach(function () {
    config()->set('fin-mail.utm.enabled', true);
});

/**
 * Decode an HTML attribute value back to a plain URL for assertions.
 */
function decodeHref(string $html): string
{
    return html_entity_decode($html, ENT_QUOTES);
}

it('is enabled based on config', function () {
    config()->set('fin-mail.utm.enabled', true);
    expect(UtmComposer::enabled())->toBeTrue();

    config()->set('fin-mail.utm.enabled', false);
    expect(UtmComposer::enabled())->toBeFalse();
});

it('round-trips per-link markers through embed and extract', function () {
    $href = UtmComposer::embedLinkMarkers('https://example.com/page', 'hero-cta', 'sale');

    expect($href)->toContain('fm_utm=1');

    [$base, $hasUtm, $content, $term] = UtmComposer::extractLinkMarkers($href);

    expect($base)->toBe('https://example.com/page')
        ->and($hasUtm)->toBeTrue()
        ->and($content)->toBe('hero-cta')
        ->and($term)->toBe('sale');
});

it('extracts a clean base URL preserving hand-typed query params', function () {
    $href = UtmComposer::embedLinkMarkers('https://example.com/page?ref=abc', 'cta', null);

    [$base, $hasUtm] = UtmComposer::extractLinkMarkers($href);

    expect($hasUtm)->toBeTrue()
        ->and($base)->toContain('ref=abc')
        ->and($base)->not->toContain('fm_utm');
});

it('reports no UTM for a plain URL without markers', function () {
    [$base, $hasUtm, $content, $term] = UtmComposer::extractLinkMarkers('https://example.com');

    expect($base)->toBe('https://example.com')
        ->and($hasUtm)->toBeFalse()
        ->and($content)->toBeNull()
        ->and($term)->toBeNull();
});

it('composes template defaults and per-link values onto an opted-in inline link', function () {
    $href = UtmComposer::embedLinkMarkers('https://example.com/page', 'hero', 'kw');
    $html = '<a href="'.htmlspecialchars($href, ENT_QUOTES).'">Link</a>';

    $out = decodeHref(UtmComposer::composeInlineLinks($html, [
        'utm_source' => 'newsletter',
        'utm_medium' => 'email',
        'utm_campaign' => 'summer',
    ]));

    expect($out)->toContain('utm_source=newsletter')
        ->and($out)->toContain('utm_medium=email')
        ->and($out)->toContain('utm_campaign=summer')
        ->and($out)->toContain('utm_content=hero')
        ->and($out)->toContain('utm_term=kw')
        ->and($out)->not->toContain('fm_utm')
        ->and($out)->not->toContain('fm_content');
});

it('leaves inline links without markers untouched', function () {
    $html = '<a href="https://example.com/page">Link</a>';

    expect(UtmComposer::composeInlineLinks($html, ['utm_source' => 'nl']))->toBe($html);
});

it('appends UTM after an existing query string', function () {
    $href = UtmComposer::embedLinkMarkers('https://example.com/p?ref=a', 'c1', null);
    $html = '<a href="'.htmlspecialchars($href, ENT_QUOTES).'">L</a>';

    $out = decodeHref(UtmComposer::composeInlineLinks($html, ['utm_source' => 'nl']));

    expect($out)->toContain('ref=a')
        ->and($out)->toContain('utm_source=nl')
        ->and($out)->toContain('utm_content=c1');
});

it('places UTM params before a URL fragment', function () {
    $href = UtmComposer::embedLinkMarkers('https://example.com/p#section', 'c1', null);
    $html = '<a href="'.htmlspecialchars($href, ENT_QUOTES).'">L</a>';

    $out = decodeHref(UtmComposer::composeInlineLinks($html, ['utm_source' => 'nl']));

    expect($out)->toContain('utm_content=c1#section')
        ->and(mb_strpos($out, 'utm_source'))->toBeLessThan(mb_strpos($out, '#section'));
});

it('lets hand-typed utm params win over composed defaults', function () {
    $href = UtmComposer::embedLinkMarkers('https://example.com/p?utm_source=manual', 'c1', null);
    $html = '<a href="'.htmlspecialchars($href, ENT_QUOTES).'">L</a>';

    $out = decodeHref(UtmComposer::composeInlineLinks($html, ['utm_source' => 'auto']));

    expect($out)->toContain('utm_source=manual')
        ->and($out)->not->toContain('utm_source=auto')
        ->and($out)->toContain('utm_content=c1');
});

it('strips markers without appending UTM when the feature is disabled', function () {
    config()->set('fin-mail.utm.enabled', false);

    $href = UtmComposer::embedLinkMarkers('https://example.com/page', 'hero', null);
    $html = '<a href="'.htmlspecialchars($href, ENT_QUOTES).'">L</a>';

    $out = decodeHref(UtmComposer::composeInlineLinks($html, []));

    expect($out)->toBe('<a href="https://example.com/page">L</a>');
});

it('appends UTM to a button URL only when the toggle is on', function () {
    $off = UtmComposer::composeButtonUrl('https://shop.com', ['use_utm' => false], ['utm_source' => 'nl']);
    expect($off)->toBe('https://shop.com');

    $on = UtmComposer::composeButtonUrl('https://shop.com', ['use_utm' => true, 'utm_content' => 'hero'], ['utm_source' => 'nl']);
    expect($on)->toBe('https://shop.com?utm_source=nl&utm_content=hero');
});

it('skips non-http schemes even when opted in', function () {
    $defaults = ['utm_source' => 'nl'];

    expect(UtmComposer::composeButtonUrl('mailto:a@b.com', ['use_utm' => true], $defaults))->toBe('mailto:a@b.com')
        ->and(UtmComposer::composeButtonUrl('tel:+123', ['use_utm' => true], $defaults))->toBe('tel:+123')
        ->and(UtmComposer::composeButtonUrl('#anchor', ['use_utm' => true], $defaults))->toBe('#anchor');
});

it('url-encodes static values with special characters', function () {
    $url = UtmComposer::composeButtonUrl('https://shop.com', ['use_utm' => true, 'utm_content' => 'a b&c'], []);

    expect($url)->toBe('https://shop.com?utm_content=a%20b%26c');
});

it('defers token-bearing values for encoding after token replacement', function () {
    $url = UtmComposer::composeButtonUrl('https://shop.com', ['use_utm' => true, 'utm_content' => '{{ user.id }}'], []);

    // Before finalize the raw token survives so the replacer can process it.
    expect($url)->toContain('{{ user.id }}');

    $replaced = str_replace('{{ user.id }}', 'John Doe', $url);
    $final = UtmComposer::finalize($replaced);

    expect($final)->toBe('https://shop.com?utm_content=John%20Doe');
});

it('omits empty UTM values', function () {
    $url = UtmComposer::composeButtonUrl('https://shop.com', ['use_utm' => true, 'utm_content' => '', 'utm_term' => '  '], ['utm_source' => 'nl', 'utm_medium' => '']);

    expect($url)->toBe('https://shop.com?utm_source=nl');
});
