<?php

declare(strict_types=1);

namespace Zhylon\ApiResources\Http\Resources;

use Closure;
use Illuminate\Http\Request;

/**
 * Sparse fieldsets: `?fields[site]=name,status` limits the `attributes` and `relationships` of every
 * `site` resource in the response (`id`, `type` and `links` are always kept).
 *
 * Disabled by default, because the `fields` query parameter might already mean something else in
 * your app. Enable it once, e.g. in a service provider:
 *
 * ```php
 * SparseFieldsets::enable();                 // reads ?fields[type]=a,b
 * SparseFieldsets::enable('only');           // reads ?only[type]=a,b
 * SparseFieldsets::resolveUsing(fn (Request $request) => [...]); // fully custom
 * ```
 *
 * A single resource can opt in or out by overriding `BuildsApiResource::sparseFieldsFor()`.
 */
final class SparseFieldsets
{
    private static bool $enabled = false;

    private static string $parameter = 'fields';

    private static ?Closure $resolver = null;

    public static function enable(string $parameter = 'fields'): void
    {
        self::$enabled = true;
        self::$parameter = $parameter;
    }

    public static function disable(): void
    {
        self::$enabled = false;
    }

    /**
     * Registers a custom resolver returning `array<string $type, list<string> $fields>` (types that
     * are not in the map stay unrestricted). Also enables sparse fieldsets; pass `null` to remove it.
     */
    public static function resolveUsing(?callable $resolver): void
    {
        self::$resolver = null === $resolver ? null : Closure::fromCallable($resolver);

        if (null !== $resolver) {
            self::$enabled = true;
        }
    }

    public static function enabled(): bool
    {
        return self::$enabled;
    }

    /**
     * The requested fields for `$type`, or `null` if the type is unrestricted (or the feature is disabled).
     *
     * @return list<string>|null
     */
    public static function for(string $type, ?Request $request = null): ?array
    {
        return self::$enabled ? self::fromRequest($type, $request ?? request()) : null;
    }

    /**
     * Like `for()`, but ignores whether the feature is enabled.
     *
     * @return list<string>|null
     */
    public static function fromRequest(string $type, Request $request): ?array
    {
        $fieldsets = null === self::$resolver
            ? $request->query(self::$parameter)
            : (self::$resolver)($request);

        if (! is_array($fieldsets) || ! array_key_exists($type, $fieldsets)) {
            return null;
        }

        $fields = $fieldsets[$type];
        $fields = is_array($fields) ? $fields : explode(',', (string) $fields);

        return array_values(array_filter(array_map('trim', $fields), fn ($field) => '' !== $field));
    }

    /**
     * Keeps only the requested keys of `$members`. `null` fields means "keep everything".
     *
     * @param  list<string>|null  $fields
     */
    public static function filter(array $members, ?array $fields): array
    {
        return null === $fields ? $members : array_intersect_key($members, array_flip($fields));
    }

    /**
     * Resets all configuration (mainly for tests).
     */
    public static function flush(): void
    {
        self::$enabled = false;
        self::$parameter = 'fields';
        self::$resolver = null;
    }
}
