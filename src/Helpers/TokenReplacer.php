<?php

declare(strict_types=1);

namespace FinityLabs\FinMail\Helpers;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Replaces tokens in email template content.
 *
 * Supports:
 *  - Model attributes:   {{ user.name }}
 *  - Config values:       {{ config.app.name }}
 *  - Fallback values:     {{ user.name | 'Valued Customer' }}
 *  - Conditionals:        {% if user.is_premium %} ... {% endif %}
 */
class TokenReplacer
{
    protected string $open;

    protected string $close;

    /** @var array<int, string> */
    protected array $allowedConfigKeys;

    public function __construct()
    {
        $this->open = config('fin-mail.tokens.open', '{{');
        $this->close = config('fin-mail.tokens.close', '}}');
        $this->allowedConfigKeys = config('fin-mail.tokens.allowed_config_keys', []);
    }

    /**
     * Replace all tokens in the given content.
     *
     * @param  array<string, mixed>  $models  Keyed by prefix: ['user' => $userModel, 'invoice' => $invoiceModel]
     * @param  array<int, string>  $blankTokens  Tokens that render as nothing when they resolve to no value,
     *                                           instead of being left as literal `{{ token }}`. Used when the
     *                                           values come from a source that is expected to cover every token
     *                                           — a recipient CSV — so a gap can never reach the recipient's
     *                                           inbox as raw template syntax. Any `| 'fallback'` still wins.
     */
    public function replace(string $content, array $models = [], array $blankTokens = []): string
    {
        $content = $this->replaceConditionals($content, $models);

        return $this->replaceSimpleTokens($content, $models, $blankTokens);
    }

    /**
     * Replace simple {{ model.attribute }} and {{ config.key }} tokens.
     *
     * @param  array<int, string>  $blankTokens
     */
    protected function replaceSimpleTokens(string $content, array $models, array $blankTokens = []): string
    {
        $open = preg_quote($this->open, '/');
        $close = preg_quote($this->close, '/');

        $pattern = "/{$open}\s*(.+?)\s*{$close}/";

        return (string) preg_replace_callback($pattern, function (array $matches) use ($models, $blankTokens): string {
            $expression = trim($matches[1]);

            $fallback = null;
            if (str_contains($expression, '|')) {
                [$expression, $fallbackRaw] = array_map('trim', explode('|', $expression, 2));
                $fallback = trim($fallbackRaw, "\"' ");
            }

            $value = $this->resolveToken($expression, $models);

            if ($value === null && $fallback === null && in_array($expression, $blankTokens, true)) {
                return '';
            }

            return $value ?? $fallback ?? $matches[0];
        }, $content);
    }

    /**
     * Resolve a single token like "user.name", "config.app.name", or "url".
     *
     * @param  array<string, mixed>  $models
     */
    protected function resolveToken(string $token, array $models): ?string
    {
        // Config tokens: config.app.name
        if (str_starts_with($token, 'config.')) {
            $configKey = Str::after($token, 'config.');
            if (in_array($configKey, $this->allowedConfigKeys, true)) {
                $value = config($configKey);

                return is_string($value) ? $value : null;
            }

            return null;
        }

        // Top-level tokens (no dot): {{ url }}, {{ message }}
        if (! str_contains($token, '.')) {
            if (isset($models[$token])) {
                return $this->castToString($models[$token]);
            }

            return null;
        }

        // Model tokens: user.name, invoice.total, etc.
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return null;
        }

        [$prefix, $attribute] = $parts;

        if (! isset($models[$prefix])) {
            return null;
        }

        $model = $models[$prefix];

        // Support nested attributes: order.customer.name
        if (str_contains($attribute, '.')) {
            return $this->resolveNestedAttribute($model, $attribute);
        }

        // Support Eloquent accessors and regular attributes
        if ($model instanceof Model) {
            $value = $model->getAttribute($attribute);
        } elseif (is_object($model)) {
            $value = $model->{$attribute} ?? null;
        } elseif (is_array($model)) {
            $value = $model[$attribute] ?? null;
        } else {
            return null;
        }

        return $this->castToString($value);
    }

    /**
     * Resolve nested dot notation on a model: order.customer.name
     */
    protected function resolveNestedAttribute(mixed $model, string $path): ?string
    {
        $segments = explode('.', $path);
        $current = $model;

        foreach ($segments as $segment) {
            if ($current instanceof Model) {
                $current = $current->getAttribute($segment);
            } elseif (is_object($current)) {
                $current = $current->{$segment} ?? null;
            } elseif (is_array($current)) {
                $current = $current[$segment] ?? null;
            } else {
                return null;
            }

            if ($current === null) {
                return null;
            }
        }

        return $this->castToString($current);
    }

    /**
     * Replace {% if model.attribute %} ... {% endif %} conditionals.
     */
    protected function replaceConditionals(string $content, array $models): string
    {
        $pattern = '/\{%\s*if\s+(.+?)\s*%\}(.*?)(?:\{%\s*else\s*%\}(.*?))?\{%\s*endif\s*%\}/s';

        return (string) preg_replace_callback($pattern, function (array $matches) use ($models): string {
            $token = trim($matches[1]);
            $truthyContent = $matches[2];
            $falsyContent = $matches[3] ?? '';

            $value = $this->resolveToken($token, $models);
            $isTruthy = ! empty($value) && $value !== 'false';

            return $isTruthy ? $truthyContent : $falsyContent;
        }, $content);
    }

    /**
     * Cast a value to string for template insertion.
     */
    protected function castToString(mixed $value): ?string
    {
        if (is_null($value)) {
            return null;
        }

        if (is_string($value) || is_numeric($value)) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value instanceof \DateTimeInterface) {
            $format = app('fin-mail')->dateFormat();

            return $format ? $value->format($format) : $value->format('Y-m-d');
        }

        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        return null;
    }

    /**
     * Extract all token keys from a template string.
     *
     * @return array<int, string>
     */
    public static function extractTokens(string $content): array
    {
        $instance = new static;
        $open = preg_quote($instance->open, '/');
        $close = preg_quote($instance->close, '/');

        preg_match_all("/{$open}\s*(.+?)\s*{$close}/", $content, $matches);

        return array_unique(array_map(function (string $token): string {
            if (str_contains($token, '|')) {
                $token = trim(explode('|', $token, 2)[0]);
            }

            return $token;
        }, $matches[1] ?? []));
    }
}
