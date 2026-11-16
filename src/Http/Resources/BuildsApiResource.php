<?php

declare(strict_types=1);

namespace Zhylon\ApiResources\Http\Resources;

use Closure;
use ReflectionClass;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\MissingValue;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\PotentiallyMissing;

/**
 * @mixin JsonResource
 */
trait BuildsApiResource
{
    /**
     * @var list<array{resource: JsonResource, document: array<string, mixed>}>
     */
    protected array $apiIncluded = [];

    /**
     * @var array<string, mixed>|null
     */
    protected ?array $apiDocument = null;

    /**
     * @var list<array<string, mixed>|Closure>
     */
    protected array $apiTopLevelMeta = [];

    /**
     * @var array<string, string|array<string, mixed>|Closure|PotentiallyMissing|null>
     */
    protected array $apiTopLevelLinks = [];

    public static function collection($resource): ApiResourceCollection
    {
        $collection = new ApiResourceCollection($resource, static::class);

        // Read the default value instead of instantiating the resource just for this flag.
        $collection->preserveKeys = true === ((new ReflectionClass(static::class))->getDefaultProperties()['preserveKeys'] ?? false);

        return $collection;
    }

    /**
     * @param  array<string, mixed>  $attributes  `Closure` values are only evaluated if the attribute is rendered
     * @param  bool|list<string>  $timestamps  `true` for `created_at`/`updated_at`, or a list of date attributes
     * @param  array<string, Relationship|array<string, mixed>|Closure|MissingValue|null>  $relationships
     * @param  array<string, string|array<string, mixed>|Closure|PotentiallyMissing|null>  $links  merged after `self`; `null` links are dropped
     * @param  array<string, mixed>|Closure  $meta  resource-level meta, e.g. permissions
     * @return array<string, mixed>
     */
    protected function toApiResource(
        string $type,
        array $attributes,
        ?string $selfHref = null,
        bool|array $timestamps = false,
        string|int|null $id = null,
        array $relationships = [],
        array $links = [],
        array|Closure $meta = [],
    ): array {
        $this->apiIncluded = [];
        $fields = $this->sparseFieldsFor($type);

        $timestamps = match ($timestamps) {
            true    => ['created_at', 'updated_at'],
            false   => [],
            default => $timestamps,
        };

        foreach ($timestamps as $field) {
            $attributes[$field] = fn () => $this->formatTimestamp($this->{$field});
        }

        $attributes = array_map(
            fn ($value) => $value instanceof Closure ? $value() : $value,
            SparseFieldsets::filter($attributes, $fields),
        );

        $payload = [
            'id'         => ResourceId::normalize($id ?? $this->resolveResourceId()),
            'type'       => $type,
            'attributes' => $attributes,
        ];

        $relationships = $this->resolveRelationships(SparseFieldsets::filter($relationships, $fields));

        if ([] !== $relationships) {
            $payload['relationships'] = $relationships;
        }

        $links = Link::formatAll(['self' => $selfHref, ...$links]);

        if ([] !== $links) {
            $payload['links'] = $links;
        }

        if ([] !== ($meta = value($meta))) {
            $payload['meta'] = $meta;
        }

        return $this->apiDocument = $payload;
    }

    /**
     * Formats a date attribute listed in `timestamps` (default: ISO-8601 / ATOM). Override for another format.
     */
    protected function formatTimestamp(mixed $value): mixed
    {
        return $value instanceof DateTimeInterface ? $value->format(DateTimeInterface::ATOM) : $value;
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

            if (! is_object($current)
                || ! method_exists($current, 'relationLoaded')
                || ! method_exists($current, 'getRelation')
                || ! $current->relationLoaded($segment)) {
                return new MissingValue;
            }

            $current = $current->getRelation($segment);
        }

        return $current;
    }

    /**
     * @param  array<string, Relationship|array<string, mixed>|Closure|MissingValue|null>  $relationships
     * @return array<string, array<string, mixed>>
     */
    protected function resolveRelationships(array $relationships): array
    {
        $resolved = [];

        foreach ($relationships as $name => $relationship) {
            $relationship = $relationship instanceof Closure ? $relationship() : $relationship;

            if ($relationship instanceof Relationship) {
                $builder = $relationship;
                $relationship = $builder->resolve(request());

                array_push($this->apiIncluded, ...$builder->includedResources());
            }

            if (null === $relationship || ($relationship instanceof PotentiallyMissing && $relationship->isMissing())) {
                continue;
            }

            $resolved[$name] = $relationship;
        }

        return $resolved;
    }

    /**
     * The requested sparse fieldset for this resource's type (`null` = all fields). Override to opt a
     * single resource in or out, e.g. `return SparseFieldsets::fromRequest($type, request());`.
     *
     * @return list<string>|null
     */
    protected function sparseFieldsFor(string $type): ?array
    {
        return SparseFieldsets::for($type);
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

    // ---------------------------------------------------------------------
    // Top-level document members (only used when this resource is the response itself)
    // ---------------------------------------------------------------------

    /**
     * Adds top-level `meta` to the response. Merges across calls; a `Closure` is evaluated when rendered.
     *
     * @param  array<string, mixed>|Closure  $meta
     */
    public function withMeta(array|Closure $meta): static
    {
        $this->apiTopLevelMeta[] = $meta;

        return $this;
    }

    /**
     * Adds top-level `links` to the response (formatted as `{ href }`).
     *
     * @param  array<string, string|array<string, mixed>|Closure|PotentiallyMissing|null>  $links
     */
    public function withLinks(array $links): static
    {
        $this->apiTopLevelLinks = [...$this->apiTopLevelLinks, ...$links];

        return $this;
    }

    /**
     * Adds `included`, `links` and `meta` to the top level. If you override `with()` in your resource,
     * merge `$this->apiTopLevel($request)` into its result to keep them.
     *
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public function with($request)
    {
        return array_merge_recursive(parent::with($request), $this->apiTopLevel($request));
    }

    /**
     * @return array<string, mixed>
     */
    public function apiTopLevel(Request $request): array
    {
        return TopLevel::members(
            CompoundDocument::included([$this]),
            $this->apiTopLevelLinks,
            $this->apiTopLevelMeta,
        );
    }

    /**
     * The document rendered by the last `toApiResource()` call.
     *
     * @return array<string, mixed>|null
     */
    public function apiDocument(): ?array
    {
        return $this->apiDocument;
    }

    /**
     * The related resources rendered by the last `toApiResource()` call that belong into `included`.
     *
     * @return list<array{resource: JsonResource, document: array<string, mixed>}>
     */
    public function apiIncludedResources(): array
    {
        return $this->apiIncluded;
    }
}
