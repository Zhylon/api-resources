<?php

declare(strict_types=1);

namespace Zhylon\ApiResources\Http\Resources;

use Closure;

/**
 * Resolves the public `id` of a resource (or of a related resource inside a relationship).
 *
 * Used by both `BuildsApiResource::resolveResourceId()` and `Relationship`, so ids are consistent
 * everywhere. Resolution order of the default chain:
 *
 * 1. Scalars (`int`/`string`) are returned as-is — handy for foreign keys like `$this->site_id`.
 * 2. Arrays: their `id` key.
 * 3. `safeHashID()` on the object, if it defines one.
 * 4. `hashID()`, if the object implements `TobyMaxham\HashId\Interfaces\Hashing`.
 * 5. `getKey()`, or the `id` property as a last resort.
 *
 * An app-wide resolver registered via `resolveUsing()` runs first; returning `null` from it falls
 * back to the default chain.
 */
final class ResourceId
{
    private static ?Closure $resolver = null;

    /**
     * Registers an app-wide id resolver, e.g. in a service provider:
     * `ResourceId::resolveUsing(fn ($resource) => $resource instanceof Model ? $resource->uuid : null)`.
     *
     * Pass `null` to remove it again.
     */
    public static function resolveUsing(?callable $resolver): void
    {
        self::$resolver = null === $resolver ? null : Closure::fromCallable($resolver);
    }

    public static function resolve(mixed $resource): string|int|null
    {
        if (null !== self::$resolver) {
            $resolved = (self::$resolver)($resource);

            if (null !== $resolved) {
                return $resolved;
            }
        }

        return self::resolveDefault($resource);
    }

    public static function resolveDefault(mixed $resource): string|int|null
    {
        if (is_int($resource) || is_string($resource)) {
            return $resource;
        }

        if (is_array($resource)) {
            return $resource['id'] ?? null;
        }

        if (! is_object($resource)) {
            return null;
        }

        if (method_exists($resource, 'safeHashID')) {
            return $resource->safeHashID();
        }

        $hashingInterface = 'TobyMaxham\HashId\Interfaces\Hashing';

        if (interface_exists($hashingInterface) && $resource instanceof $hashingInterface) {
            return $resource->hashID();
        }

        if (method_exists($resource, 'getKey')) {
            return $resource->getKey();
        }

        return $resource->id ?? null;
    }
}
