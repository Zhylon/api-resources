<?php

declare(strict_types=1);

namespace Zhylon\ApiResources\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin JsonResource
 */
trait BuildsApiResource
{
    public static function collection($resource): ApiResourceCollection
    {
        return tap(new ApiResourceCollection($resource, static::class), function (ApiResourceCollection $collection) {
            if (property_exists(static::class, 'preserveKeys')) {
                $collection->preserveKeys = true === (new static([]))->preserveKeys;
            }
        });
    }

    protected function toApiResource(
        string $type,
        array $attributes,
        ?string $selfHref = null,
        bool $timestamps = false,
        string|int|null $id = null,
    ): array {
        if ($timestamps) {
            $attributes = [
                ...$attributes,
                'created_at' => $this->created_at?->toAtomString(),
                'updated_at' => $this->updated_at?->toAtomString(),
            ];
        }

        return [
            'id'         => $id ?? $this->resolveResourceId(),
            'type'       => $type,
            'attributes' => $attributes,
            'links'      => [
                'self' => ['href' => $selfHref],
            ],
        ];
    }

    /**
     * Resolves the resource's `id`, without requiring any hashid package to be installed:
     *
     * 1. `safeHashID()` on the underlying resource, if it defines one.
     * 2. `hashID()`, if the underlying resource implements `TobyMaxham\HashId\Interfaces\Hashing`.
     * 3. The resource's primary key (`getKey()`), or its `id` property as a last resort.
     *
     * Override this in the resource class for full custom control.
     */
    protected function resolveResourceId(): string|int|null
    {
        $resource = $this->resource;

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

        return $this->id ?? null;
    }
}
