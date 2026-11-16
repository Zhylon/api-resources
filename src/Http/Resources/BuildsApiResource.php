<?php

declare(strict_types=1);

namespace Zhylon\ApiResources\Http\Resources;

use Closure;
use Illuminate\Http\Resources\MissingValue;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\PotentiallyMissing;

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

    /**
     * @param  array<string, Relationship|array|Closure|MissingValue|null>  $relationships
     */
    protected function toApiResource(
        string $type,
        array $attributes,
        ?string $selfHref = null,
        bool $timestamps = false,
        string|int|null $id = null,
        array $relationships = [],
    ): array {
        if ($timestamps) {
            $attributes = [
                ...$attributes,
                'created_at' => $this->created_at?->toAtomString(),
                'updated_at' => $this->updated_at?->toAtomString(),
            ];
        }

        $payload = [
            'id'         => $id ?? $this->resolveResourceId(),
            'type'       => $type,
            'attributes' => $attributes,
        ];

        if ([] !== ($relationships = $this->resolveRelationships($relationships))) {
            $payload['relationships'] = $relationships;
        }

        $payload['links'] = [
            'self' => ['href' => $selfHref],
        ];

        return $payload;
    }

    /**
     * Starts a relationship for an Eloquent relation of the underlying resource. Its data is only
     * present if the relation is loaded — the app decides what to load (e.g. from `?include=`).
     *
     * `$data` overrides the related data, e.g. a foreign key (`$this->site_id`) to render the
     * identifier without loading the relation, or a `Closure` evaluated lazily.
     */
    protected function relationship(string $relation, string|Closure|null $type = null, mixed $data = new MissingValue): Relationship
    {
        if (func_num_args() < 3) {
            $data = $this->loadedRelation($relation);
        }

        return $this->newRelationship($data, $type);
    }

    /**
     * Factory for every relationship created by `relationship()`. Override it (e.g. in a base resource)
     * to return a `Relationship` subclass or to apply app-wide defaults such as a `related` link convention.
     */
    protected function newRelationship(mixed $data, string|Closure|null $type): Relationship
    {
        return new Relationship($data, $type);
    }

    /**
     * Returns the loaded relation, or a `MissingValue` if it isn't loaded (or the resource isn't Eloquent).
     * Dot notation (`site.server`) walks nested loaded relations; a loaded but empty (`null`)
     * intermediate relation yields `null`.
     */
    protected function loadedRelation(string $relation): mixed
    {
        $current = $this->resource;

        foreach (explode('.', $relation) as $index => $segment) {
            if (null === $current && $index > 0) {
                return null;
            }

            if (! is_object($current) || ! method_exists($current, 'relationLoaded') || ! $current->relationLoaded($segment)) {
                return new MissingValue;
            }

            $current = $current->getRelation($segment);
        }

        return $current;
    }

    /**
     * @param  array<string, Relationship|array|Closure|MissingValue|null>  $relationships
     */
    protected function resolveRelationships(array $relationships): array
    {
        $resolved = [];

        foreach ($relationships as $name => $relationship) {
            $relationship = $relationship instanceof Closure ? $relationship() : $relationship;

            if ($relationship instanceof Relationship) {
                $relationship = $relationship->resolve();
            }

            if (null === $relationship || ($relationship instanceof PotentiallyMissing && $relationship->isMissing())) {
                continue;
            }

            $resolved[$name] = $relationship;
        }

        return $resolved;
    }

    /**
     * Resolves the resource's `id` via `ResourceId::resolve()`, without requiring any hashid package:
     *
     * 1. `safeHashID()` on the underlying resource, if it defines one.
     * 2. `hashID()`, if the underlying resource implements `TobyMaxham\HashId\Interfaces\Hashing`.
     * 3. The resource's primary key (`getKey()`), or its `id` property as a last resort.
     *
     * Override this in the resource class for full custom control.
     */
    protected function resolveResourceId(): string|int|null
    {
        return ResourceId::resolve($this->resource);
    }
}
