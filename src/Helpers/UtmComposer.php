<?php

declare(strict_types=1);

namespace FinityLabs\FinMail\Helpers;

/**
 * Composes UTM tracking parameters onto links and CTA buttons in email bodies.
 *
 * The feature is opt-in per link. Inline links carry their per-link state in
 * the stored href via internal markers ({@see self::MARKER}); CTA buttons carry
 * it in their block config. At render time, template-level defaults
 * (utm_source / utm_medium / utm_campaign) are merged with the per-link
 * utm_content / utm_term and appended to the URL.
 *
 * UTM values may contain {{ tokens }}. Because composition happens before the
 * token replacer runs, token-bearing values are wrapped in an encoding sentinel
 * ({@see self::ENC_OPEN}) and URL-encoded by {@see self::finalize()} after the
 * tokens have been resolved.
 */
final class UtmComposer
{
    /**
     * Internal marker that flags an inline link as UTM-enabled. Stripped at render.
     */
    public const MARKER = 'fm_utm';

    /**
     * Internal query key holding a link's per-link utm_content value.
     */
    public const P_CONTENT = 'fm_content';

    /**
     * Internal query key holding a link's per-link utm_term value.
     */
    public const P_TERM = 'fm_term';

    private const ENC_OPEN = '__FM_UTM_ENC_OPEN__';

    private const ENC_CLOSE = '__FM_UTM_ENC_CLOSE__';

    public static function enabled(): bool
    {
        return (bool) config('fin-mail.utm.enabled', true);
    }

    /**
     * Compose UTM params onto every UTM-enabled inline <a href="..."> in the HTML.
     *
     * Links without the internal marker are left untouched (opted out). When the
     * feature is disabled, markers are stripped so nothing leaks to recipients.
     *
     * @param  array<string, string>  $defaults  Template-level defaults keyed by utm_* param
     */
    public static function composeInlineLinks(string $html, array $defaults): string
    {
        return preg_replace_callback(
            '/(<a\b[^>]*?\bhref=")([^"]*)("[^>]*>)/i',
            function (array $matches) use ($defaults): string {
                $href = html_entity_decode($matches[2], ENT_QUOTES);

                return $matches[1].htmlspecialchars(self::processLinkHref($href, $defaults), ENT_QUOTES).$matches[3];
            },
            $html,
        ) ?? $html;
    }

    /**
     * Compose UTM params onto a CTA button URL.
     *
     * @param  array<string, mixed>  $config  Button block config
     * @param  array<string, string>  $defaults  Template-level defaults keyed by utm_* param
     */
    public static function composeButtonUrl(string $baseUrl, array $config, array $defaults): string
    {
        if (! self::enabled() || empty($config['use_utm'])) {
            return $baseUrl;
        }

        $content = isset($config['utm_content']) ? (string) $config['utm_content'] : null;
        $term = isset($config['utm_term']) ? (string) $config['utm_term'] : null;

        return self::composeUrl($baseUrl, self::paramsFor($defaults, $content, $term));
    }

    /**
     * Embed the internal per-link markers into a base URL. Used when saving an
     * inline link so its UTM state round-trips through the stored href.
     */
    public static function embedLinkMarkers(string $baseUrl, ?string $content, ?string $term): string
    {
        [$path, $query, $fragment] = self::splitUrl($baseUrl);

        parse_str($query, $params);
        $params[self::MARKER] = '1';

        if (filled($content)) {
            $params[self::P_CONTENT] = $content;
        } else {
            unset($params[self::P_CONTENT]);
        }

        if (filled($term)) {
            $params[self::P_TERM] = $term;
        } else {
            unset($params[self::P_TERM]);
        }

        $rebuilt = http_build_query($params);

        return $path.($rebuilt !== '' ? '?'.$rebuilt : '').$fragment;
    }

    /**
     * Extract per-link UTM state from a stored href.
     *
     * @return array{0: string, 1: bool, 2: string|null, 3: string|null} [cleanBaseUrl, isEnabled, content, term]
     */
    public static function extractLinkMarkers(string $url): array
    {
        [$path, $query, $fragment] = self::splitUrl($url);

        parse_str($query, $params);

        $hasUtm = array_key_exists(self::MARKER, $params);
        $content = isset($params[self::P_CONTENT]) && is_string($params[self::P_CONTENT]) ? $params[self::P_CONTENT] : null;
        $term = isset($params[self::P_TERM]) && is_string($params[self::P_TERM]) ? $params[self::P_TERM] : null;

        unset($params[self::MARKER], $params[self::P_CONTENT], $params[self::P_TERM]);

        $rebuilt = http_build_query($params);
        $base = $path.($rebuilt !== '' ? '?'.$rebuilt : '').$fragment;

        return [$base, $hasUtm, $content, $term];
    }

    /**
     * Resolve the encoding sentinels wrapping token-bearing UTM values,
     * URL-encoding the now-resolved values. Runs after token replacement.
     */
    public static function finalize(string $html): string
    {
        $open = preg_quote(self::ENC_OPEN, '/');
        $close = preg_quote(self::ENC_CLOSE, '/');

        return preg_replace_callback(
            "/{$open}(.*?){$close}/s",
            fn (array $matches): string => rawurlencode($matches[1]),
            $html,
        ) ?? $html;
    }

    /**
     * Append UTM params (already ordered, raw) to a base URL.
     *
     * Skips non-http schemes, honours existing query params (hand-typed
     * utm_* win), and keeps params before any #fragment.
     *
     * @param  array<string, string>  $rawParams
     */
    private static function composeUrl(string $baseUrl, array $rawParams): string
    {
        $trimmed = mb_ltrim($baseUrl);

        if ($trimmed === '' || preg_match('/^(mailto:|tel:|#)/i', $trimmed)) {
            return $baseUrl;
        }

        [$path, $query, $fragment] = self::splitUrl($baseUrl);

        parse_str($query, $existing);

        $pairs = $query !== '' ? [$query] : [];

        foreach ($rawParams as $key => $value) {
            $value = mb_trim($value);

            if ($value === '' || array_key_exists($key, $existing)) {
                continue;
            }

            $pairs[] = $key.'='.self::encodeValue($value);
        }

        $newQuery = implode('&', $pairs);

        return $path.($newQuery !== '' ? '?'.$newQuery : '').$fragment;
    }

    /**
     * Build the ordered utm_* param list from defaults + per-link values,
     * omitting empties.
     *
     * @param  array<string, string>  $defaults
     * @return array<string, string>
     */
    private static function paramsFor(array $defaults, ?string $content, ?string $term): array
    {
        return array_filter([
            'utm_source' => (string) ($defaults['utm_source'] ?? ''),
            'utm_medium' => (string) ($defaults['utm_medium'] ?? ''),
            'utm_campaign' => (string) ($defaults['utm_campaign'] ?? ''),
            'utm_content' => (string) ($content ?? ''),
            'utm_term' => (string) ($term ?? ''),
        ], fn (string $value): bool => mb_trim($value) !== '');
    }

    /**
     * URL-encode a static value now, or defer token-bearing values to
     * {@see self::finalize()} via an encoding sentinel.
     */
    private static function encodeValue(string $value): string
    {
        if (str_contains($value, '{{') || str_contains($value, '{%')) {
            return self::ENC_OPEN.$value.self::ENC_CLOSE;
        }

        return rawurlencode($value);
    }

    /**
     * @param  array<string, string>  $defaults
     */
    private static function processLinkHref(string $href, array $defaults): string
    {
        [$base, $hasUtm, $content, $term] = self::extractLinkMarkers($href);

        if (! $hasUtm) {
            return $href;
        }

        if (! self::enabled()) {
            return $base;
        }

        return self::composeUrl($base, self::paramsFor($defaults, $content, $term));
    }

    /**
     * Split a URL into [pathWithoutQueryOrFragment, query, fragmentWithHash].
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private static function splitUrl(string $url): array
    {
        $fragment = '';
        $hashPos = mb_strpos($url, '#');

        if ($hashPos !== false) {
            $fragment = mb_substr($url, $hashPos);
            $url = mb_substr($url, 0, $hashPos);
        }

        $query = '';
        $queryPos = mb_strpos($url, '?');

        if ($queryPos !== false) {
            $query = mb_substr($url, $queryPos + 1);
            $url = mb_substr($url, 0, $queryPos);
        }

        return [$url, $query, $fragment];
    }
}
