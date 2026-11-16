<?php

declare(strict_types=1);

namespace Zhylon\ApiResources\Http\Resources;

use Closure;
use Traversable;
use LogicException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Http\Resources\MissingValue;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\PotentiallyMissing;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Fluent builder for a single entry of a resource's `relationships` object:
 *
 * ```json
 * "site": {
 *     "links": { "related": { "href": "..." } },
 *     "data":  { "id": "abc", "type": "site" },
 *     "meta":  { ... }
 * }
 * ```
 *
 * Semantics:
 * - Related data that is *missing* (a `MissingValue`, e.g. a relation that isn't loaded) produces no
 *   `data` key. If links/meta are configured they're still rendered, otherwise the whole relationship
 *   is omitted. `omitWhenMissing()` always omits it.
 * - Related data that is loaded but empty renders `"data": null` (to-one) or `"data": []` (to-many).
 * - Related data may be models, plain objects, arrays, scalar ids (e.g. a foreign key, without
 *   loading the relation), `JsonResource`s / resource collections (they're unwrapped), iterables,
 *   or a `Closure` returning any of those (evaluated lazily, only when rendered).
 * - With a resource class (`resource()`, or implicitly when the data is a `JsonResource`), every
 *   related item is rendered through it: the identifier takes its `id`/`type` from that rendering,
 *   and the full object ends up in the top-level `included` array of the response.
 *
 * Every step is a protected method, so app-specific conventions can live in a subclass — return it
 * from `BuildsApiResource::newRelationship()` to use it everywhere.
 *
 * @phpstan-consistent-constructor
 */
class Relationship
{
    protected static int $maxDepth = 16;

    protected static int $depth = 0;

    protected mixed $data;

    protected string|Closure|null $type;

    protected ?bool $many;

    protected ?Closure $idResolver = null;

    /**
     * @var array<string, mixed>|Closure|null
     */
    protected array|Closure|null $identifierMeta = null;

    /**
     * @var array<string, string|array<string, mixed>|Closure|null>
     */
    protected array $links = [];

    /**
     * @var array<string, mixed>|Closure
     */
    protected array|Closure $meta = [];

    protected ?string $countKey = null;

    protected int|Closure|null $count = null;

    protected bool $withData = true;

    protected bool $omitWhenMissing = false;

    protected bool|Closure $condition = true;

    /** @var class-string<JsonResource>|Closure|null */
    protected string|Closure|null $resource = null;

    /** @var class-string<JsonResource>|null */
    protected ?string $detectedResource = null;

    protected bool|Closure $include = true;

    /**
     * @var list<array{resource: JsonResource, document: array<string, mixed>}>
     */
    protected array $rendered = [];

    public function __construct(mixed $data = new MissingValue, string|Closure|null $type = null, ?bool $many = null)
    {
        $this->data = $data;
        $this->type = $type;
        $this->many = $many;
    }

    /**
     * Cardinality is detected from the data: collections, iterables and list-arrays are to-many,
     * everything else is to-one.
     */
    public static function make(mixed $data = new MissingValue, string|Closure|null $type = null): static
    {
        return new static($data, $type);
    }

    public static function toOne(string|Closure|null $type, mixed $data = new MissingValue): static
    {
        return new static($data, $type, false);
    }

    public static function toMany(string|Closure|null $type, mixed $data = new MissingValue): static
    {
        return new static($data, $type, true);
    }

    /**
     * A relationship without resource linkage, e.g. only a `related` link for a large to-many relation.
     *
     * @param  array<string, string|array<string, mixed>|Closure|null>  $links
     */
    public static function linksOnly(array $links = []): static
    {
        return (new static)->links($links)->withoutData();
    }

    /**
     * Guards against endless rendering of cyclic relations (e.g. via `chaperone()`), default 16.
     */
    public static function maxDepth(int $depth): void
    {
        static::$maxDepth = $depth;
    }

    // ---------------------------------------------------------------------
    // Configuration
    // ---------------------------------------------------------------------

    public function data(mixed $data): static
    {
        $this->data = $data;

        return $this;
    }

    /**
     * The related resource type, or a `Closure` receiving each related item (for polymorphic
     * relations, e.g. `fn ($model) => $model->getMorphClass()`). Optional with `resource()`.
     */
    public function type(string|Closure $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function one(): static
    {
        $this->many = false;

        return $this;
    }

    public function many(): static
    {
        $this->many = true;

        return $this;
    }

    /**
     * Renders every related item through this resource: identifiers use its `id`/`type`, and the full
     * object is added to the top-level `included` array. A `Closure` receives the item and returns a
     * `JsonResource` (e.g. to pick a resource per morph type).
     *
     * @param  class-string<JsonResource>|Closure  $resource
     */
    public function resource(string|Closure $resource): static
    {
        $this->resource = $resource;

        return $this;
    }

    /**
     * Whether rendered related resources go into `included` (default: yes, whenever a resource class
     * is known). E.g. `->included(fn () => in_array('site', $includes))`.
     */
    public function included(bool|Closure $include = true): static
    {
        $this->include = $include;

        return $this;
    }

    /**
     * Keeps the identifiers but never adds the related resources to `included`.
     */
    public function withoutIncluded(): static
    {
        return $this->included(false);
    }

    /**
     * Overrides id resolution for the related items of this relationship (defaults to the related
     * resource's `id`, or `ResourceId::resolve()` without one).
     */
    public function identifyUsing(callable $resolver): static
    {
        $this->idResolver = Closure::fromCallable($resolver);

        return $this;
    }

    /**
     * Meta added to every resource identifier, e.g. pivot data:
     * `->identifierMeta(fn ($tag) => ['position' => $tag->pivot->position])`.
     *
     * @param  array<string, mixed>|Closure  $meta
     */
    public function identifierMeta(array|Closure $meta): static
    {
        $this->identifierMeta = $meta;

        return $this;
    }

    /**
     * Adds a link. `$href` may be a string, a ready-made link array, a `Closure` returning either, or
     * `null` (the link is then dropped). With `$meta`, the link is rendered as `{ href, meta }`.
     *
     * @param  string|array<string, mixed>|Closure|null  $href
     * @param  array<string, mixed>  $meta
     */
    public function link(string $name, string|array|Closure|null $href, array $meta = []): static
    {
        $this->links[$name] = [] === $meta
            ? $href
            : fn () => Link::format(value($href), $meta);

        return $this;
    }

    /**
     * @param  array<string, string|array<string, mixed>|Closure|null>  $links
     */
    public function links(array $links): static
    {
        foreach ($links as $name => $href) {
            $this->link($name, $href);
        }

        return $this;
    }

    /**
     * @param  string|array<string, mixed>|Closure|null  $href
     * @param  array<string, mixed>  $meta
     */
    public function self(string|array|Closure|null $href, array $meta = []): static
    {
        return $this->link('self', $href, $meta);
    }

    /**
     * @param  string|array<string, mixed>|Closure|null  $href
     * @param  array<string, mixed>  $meta
     */
    public function related(string|array|Closure|null $href, array $meta = []): static
    {
        return $this->link('related', $href, $meta);
    }

    /**
     * Relationship-level meta. Merges with previous calls; a `Closure` is evaluated when rendered.
     *
     * @param  array<string, mixed>|Closure  $meta
     */
    public function meta(array|Closure $meta): static
    {
        if ($meta instanceof Closure || $this->meta instanceof Closure) {
            $previous = $this->meta;
            $this->meta = fn () => [...value($previous), ...value($meta)];

            return $this;
        }

        $this->meta = [...$this->meta, ...$meta];

        return $this;
    }

    /**
     * Adds the number of related items to `meta`. Without `$count`, the loaded to-many data is counted —
     * pass it explicitly (e.g. `$this->comments_count` from `withCount()`) to avoid loading the relation.
     */
    public function withCount(int|Closure|null $count = null, string $key = 'count'): static
    {
        $this->count = $count;
        $this->countKey = $key;

        return $this;
    }

    /**
     * Never render `data` (resource linkage), only links/meta. Nothing is added to `included` either.
     */
    public function withoutData(): static
    {
        $this->withData = false;

        return $this;
    }

    /**
     * Omit the whole relationship when its data is missing, even if links/meta are configured.
     */
    public function omitWhenMissing(bool $omit = true): static
    {
        $this->omitWhenMissing = $omit;

        return $this;
    }

    public function when(bool|Closure $condition): static
    {
        $this->condition = $condition;

        return $this;
    }

    public function unless(bool|Closure $condition): static
    {
        return $this->when(fn () => ! value($condition));
    }

    // ---------------------------------------------------------------------
    // Rendering
    // ---------------------------------------------------------------------

    /**
     * Renders the relationship object, or `null` if it should be omitted.
     *
     * @return array<string, mixed>|null
     */
    public function resolve(?Request $request = null): ?array
    {
        $this->rendered = [];

        if (! value($this->condition)) {
            return null;
        }

        $data = $this->unwrap(value($this->data));
        $missing = $data instanceof MissingValue;

        // Generators and other one-shot iterables are read once, so they can be rendered *and* counted.
        if ($data instanceof Traversable && ! is_countable($data)) {
            $data = iterator_to_array($data, false);
        }

        if ($missing && $this->omitWhenMissing) {
            return null;
        }

        $many = $missing ? (bool) $this->many : $this->isMany($data);
        $relationship = [];

        if ([] !== ($links = $this->resolveLinks())) {
            $relationship['links'] = $links;
        }

        if (! $missing && $this->withData) {
            $request ??= request();

            $relationship['data'] = $many
                ? $this->resolveMany($data, $request)
                : $this->resolveOne($data, $request);
        }

        if ([] !== ($meta = $this->resolveMeta($data, $missing, $many))) {
            $relationship['meta'] = $meta;
        }

        return [] === $relationship ? null : $relationship;
    }

    /**
     * The related resources rendered during the last `resolve()` that belong into `included`.
     *
     * @return list<array{resource: JsonResource, document: array<string, mixed>}>
     */
    public function includedResources(): array
    {
        return value($this->include) ? $this->rendered : [];
    }

    /**
     * The related items (models, objects, ids …) as a collection. Empty when the data is missing.
     *
     * @return Collection<array-key, mixed>
     */
    public function relatedItems(): Collection
    {
        $data = $this->unwrap(value($this->data));

        if ($data instanceof MissingValue || null === $data) {
            return new Collection;
        }

        if ($this->isMany($data) && is_iterable($data)) {
            return (new Collection($data))->values();
        }

        return new Collection([$data]);
    }

    /**
     * Turns `JsonResource`s, resource collections and missing values into their underlying data,
     * remembering the resource class for rendering the related items.
     */
    protected function unwrap(mixed $data): mixed
    {
        if ($data instanceof PotentiallyMissing && $data->isMissing()) {
            return new MissingValue;
        }

        if ($data instanceof JsonResource && $data->resource instanceof PotentiallyMissing && $data->resource->isMissing()) {
            return new MissingValue;
        }

        if ($data instanceof ResourceCollection) {
            if (is_a($data->collects, JsonResource::class, true)) {
                $this->detectedResource ??= $data->collects;
            }

            // Missing collections were handled above, so `collection` is set here.
            return ($data->collection ?? new Collection)->map(fn ($item) => $this->unwrap($item));
        }

        if ($data instanceof JsonResource) {
            $this->detectedResource ??= $data::class;

            return $this->unwrap($data->resource);
        }

        return $data;
    }

    protected function isMany(mixed $data): bool
    {
        if (null !== $this->many) {
            return $this->many;
        }

        return $data instanceof Traversable || (is_array($data) && array_is_list($data));
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function resolveOne(mixed $item, Request $request): ?array
    {
        if ($item instanceof Traversable || (is_array($item) && array_is_list($item))) {
            $item = Collection::make($item)->first();
        }

        return null === $item ? null : $this->identifier($item, $request);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function resolveMany(mixed $items, Request $request): array
    {
        if (null === $items) {
            return [];
        }

        return array_values(Collection::make(is_iterable($items) ? $items : [$items])
            ->map(fn ($item) => $this->unwrap($item))
            ->reject(fn ($item) => null === $item || $item instanceof MissingValue)
            ->map(fn ($item) => $this->identifier($item, $request))
            ->all());
    }

    /**
     * Builds a single resource identifier object: `{ id, type, meta? }`.
     *
     * @return array<string, mixed>
     */
    protected function identifier(mixed $item, Request $request): array
    {
        $document = $this->renderRelated($item, $request);

        $identifier = [
            'id'   => ResourceId::normalize($this->resolveId($item, $document)),
            'type' => $this->resolveType($item, $document),
        ];

        if (null !== $this->identifierMeta && [] !== ($meta = value($this->identifierMeta, $item))) {
            $identifier['meta'] = $meta;
        }

        return $identifier;
    }

    /**
     * Renders the item through the related resource (if one is known) and remembers it for `included`.
     *
     * @return array<string, mixed>|null
     */
    protected function renderRelated(mixed $item, Request $request): ?array
    {
        if (null === ($resource = $this->makeRelatedResource($item))) {
            return null;
        }

        if (static::$depth >= static::$maxDepth) {
            throw new LogicException(sprintf(
                'Maximum relationship depth of %d exceeded while rendering [%s]. Do the loaded relations contain a cycle (e.g. via chaperone())? See Relationship::maxDepth().',
                static::$maxDepth,
                $resource::class,
            ));
        }

        static::$depth++;

        try {
            $document = $resource->resolve($request);
        } finally {
            static::$depth--;
        }

        $this->rendered[] = ['resource' => $resource, 'document' => $document];

        return $document;
    }

    protected function makeRelatedResource(mixed $item): ?JsonResource
    {
        $resource = $this->resource ?? $this->detectedResource;

        // Bare ids can't be rendered as a full resource.
        if (null === $resource || is_scalar($item)) {
            return null;
        }

        return $resource instanceof Closure ? $resource($item) : new $resource($item);
    }

    /**
     * @param  array<string, mixed>|null  $document
     */
    protected function resolveId(mixed $item, ?array $document): string|int|null
    {
        if (null !== $this->idResolver) {
            return ($this->idResolver)($item);
        }

        return $document['id'] ?? ResourceId::resolve($item);
    }

    /**
     * @param  array<string, mixed>|null  $document
     */
    protected function resolveType(mixed $item, ?array $document): string
    {
        $type = $this->type instanceof Closure ? ($this->type)($item) : $this->type;
        $type ??= $document['type'] ?? null;

        if (! is_string($type) || '' === $type) {
            throw new LogicException(sprintf(
                'Unable to determine the relationship type for [%s]. Pass a type or a resource() to the Relationship.',
                get_debug_type($item),
            ));
        }

        return $type;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function resolveLinks(): array
    {
        $links = [];

        foreach ($this->links as $name => $href) {
            $link = $this->formatLink(value($href));

            if (null !== $link) {
                $links[$name] = $link;
            }
        }

        return $links;
    }

    /**
     * Formats a link the same way as the resource's own links (`{ "href": "..." }`).
     * Override to change the link format, e.g. to plain strings.
     *
     * @param  string|array<string, mixed>|null  $href
     * @return array<string, mixed>|null
     */
    protected function formatLink(string|array|null $href): ?array
    {
        return Link::format($href);
    }

    /**
     * @return array<string, mixed>
     */
    protected function resolveMeta(mixed $data, bool $missing, bool $many): array
    {
        $meta = value($this->meta);

        if (null !== $this->countKey) {
            $count = value($this->count);

            if (null === $count && $many && ! $missing) {
                $count = match (true) {
                    null === $data      => 0,
                    is_countable($data) => count($data),
                    default             => 1,
                };
            }

            if (null !== $count) {
                $meta[$this->countKey] = $count;
            }
        }

        return $meta;
    }
}
