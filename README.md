# Zhylon API-Resources

[![Latest Stable Version](http://poser.pugx.org/zhylon/api-resources/v)](https://packagist.org/packages/zhylon/api-resources)
[![Tests](https://github.com/Zhylon/api-resources/actions/workflows/tests.yml/badge.svg)](https://github.com/Zhylon/api-resources/actions/workflows/tests.yml)
[![License](http://poser.pugx.org/zhylon/api-resources/license)](https://packagist.org/packages/zhylon/api-resources)

Small, opinionated helpers for building consistent, JSON:API-inspired API resource responses in Laravel:

- `BuildsApiResource` — a trait for `JsonResource` classes that produces a `{ id, type, attributes, relationships, links }` payload, and makes `YourResource::collection(...)` return an enveloped, paginated collection automatically.
- `Relationship` — a fluent builder for `relationships` entries (resource linkage, links, meta) that only renders what the app has loaded.
- `ApiResourceCollection` — the `AnonymousResourceCollection` subclass behind that: wraps items in `data` and adds `links`/`meta` pagination info for both length-aware and cursor paginators.

## Requirements

- PHP 8.2+
- Laravel 12.x or 13.x

## Installation

```bash
composer require zhylon/api-resources
```

There is nothing to publish or configure — both classes are used directly.

## Usage

### Single resources — `BuildsApiResource`

Add the trait to a `JsonResource` and call `toApiResource()` from `toArray()`:

```php
use Illuminate\Http\Resources\Json\JsonResource;
use Zhylon\ApiResources\Http\Resources\BuildsApiResource;

class OrderResource extends JsonResource
{
    use BuildsApiResource;

    public function toArray($request): array
    {
        return $this->toApiResource(
            type: 'order',
            attributes: [
                'status' => $this->status,
                'total'  => $this->total,
            ],
            selfHref: route('orders.show', $this->resource),
            timestamps: true,
        );
    }
}
```

produces:

```json
{
    "id": "abc123",
    "type": "order",
    "attributes": {
        "status": "paid",
        "total": 42.0,
        "created_at": "2026-01-01T00:00:00+00:00",
        "updated_at": "2026-01-01T00:00:00+00:00"
    },
    "links": {
        "self": { "href": "https://example.test/orders/abc123" }
    }
}
```

`toApiResource()` accepts:

| Parameter    | Type                | Description                                                             |
|--------------|---------------------|-------------------------------------------------------------------------|
| `type`       | `string`            | The JSON:API-style resource type, e.g. `'order'`                        |
| `attributes` | `array`             | The resource's own attributes                                           |
| `selfHref`   | `?string`           | Value for `links.self.href`                                             |
| `timestamps` | `bool`              | Appends `created_at`/`updated_at` (as ISO-8601 strings) to `attributes` |
| `id`         | `string\|int\|null` | Overrides automatic id resolution (see below)                           |
| `relationships` | `array`          | Relationship entries, see [Relationships](#relationships)               |

#### Resolving the `id`

When `id` isn't passed explicitly, `resolveResourceId()` resolves it in this order:

1. `safeHashID()` on the underlying resource, if it defines one.
2. `hashID()`, if the underlying resource implements `TobyMaxham\HashId\Interfaces\Hashing`.
3. The resource's primary key (`getKey()`), or its `id` property as a last resort.

None of this requires [`tobymaxham/laravel-hashid`](https://github.com/TobyMaxham/laravel-hashid) (or any other hashid package) to be installed — the checks use `method_exists()`/`interface_exists()`, so they simply no-op down the chain when unavailable.

To change id resolution app-wide (for resources *and* relationship identifiers), register a resolver once, e.g. in a service provider. Returning `null` falls back to the default chain:

```php
use Zhylon\ApiResources\Http\Resources\ResourceId;

ResourceId::resolveUsing(fn ($resource) => $resource instanceof HasUuid ? $resource->uuid : null);
```

For full custom control of a single resource, override `resolveResourceId()` in your resource class:

```php
use Illuminate\Http\Resources\Json\JsonResource;
use Zhylon\ApiResources\Http\Resources\BuildsApiResource;

class OrderResource extends JsonResource
{
    use BuildsApiResource;

    protected function resolveResourceId(): string|int|null
    {
        return $this->resource->uuid;
    }

    // ...
}
```

### Relationships

The package renders relationships; **what gets loaded is up to your app** (e.g. based on an `?include=` query parameter, via `$query->with(...)`, `$model->load(...)` or `spatie/laravel-query-builder`'s `allowedIncludes()`). A relation that isn't loaded is simply left out.

```php
use Zhylon\ApiResources\Http\Resources\Relationship;

class DeploymentResource extends JsonResource
{
    use BuildsApiResource;

    public function toArray($request): array
    {
        return $this->toApiResource(
            type: 'deployment',
            attributes: ['status' => $this->status],
            selfHref: route('deployments.show', $this->resource),
            relationships: [
                // only rendered if `site` is loaded
                'site' => $this->relationship('site', 'site'),

                // always rendered: identifier straight from the foreign key, no query needed
                'server' => $this->relationship('server', 'server', $this->server_id),

                // to-many, plus a link and a count
                'logs' => $this->relationship('logs', 'deployment-log')
                    ->related(route('deployments.logs.index', $this->resource))
                    ->withCount($this->logs_count),
            ],
        );
    }
}
```

produces (with `site` loaded, `logs` not loaded):

```json
{
    "id": "abc123",
    "type": "deployment",
    "attributes": { "status": "finished" },
    "relationships": {
        "site":   { "data": { "id": "s1", "type": "site" } },
        "server": { "data": { "id": 7, "type": "server" } },
        "logs":   {
            "links": { "related": { "href": "https://example.test/deployments/abc123/logs" } },
            "meta":  { "count": 12 }
        }
    },
    "links": { "self": { "href": "https://example.test/deployments/abc123" } }
}
```

#### Rules

| Situation                                    | Output                                                                  |
|----------------------------------------------|-------------------------------------------------------------------------|
| Relation not loaded, no links/meta           | relationship omitted                                                    |
| Relation not loaded, links/meta configured   | `{ links, meta }` without `data` (use `omitWhenMissing()` to omit)      |
| Loaded, to-one, `null`                       | `{ "data": null }`                                                      |
| Loaded, to-many, empty                       | `{ "data": [] }`                                                        |
| No relationship renders anything             | no `relationships` key at all                                           |

Identifiers use the same id resolution as the resource itself (`safeHashID()` → `hashID()` → `getKey()`, or `ResourceId::resolveUsing()`). Scalars are used as the id directly.

#### Creating relationships

| Call                                                     | Description                                                                 |
|----------------------------------------------------------|-----------------------------------------------------------------------------|
| `$this->relationship('site', 'site')`                    | Data from the Eloquent relation, `MissingValue` if not loaded               |
| `$this->relationship('site.server', 'server')`           | Nested loaded relations via dot notation                                    |
| `$this->relationship('site', 'site', $this->site_id)`    | Explicit data (foreign key, model, collection, `Closure` …)                 |
| `Relationship::toOne('site', $data)`                     | Forced to-one, independent of an Eloquent relation                          |
| `Relationship::toMany('tag', $data)`                     | Forced to-many (`null` → `[]`)                                              |
| `Relationship::make($data, 'tag')`                       | Cardinality detected (collections/iterables/list arrays → to-many)          |
| `Relationship::linksOnly(['related' => $url])`           | No resource linkage at all, only links                                      |

Data may be models, plain objects, arrays, scalar ids, iterables, `JsonResource`s and resource collections (unwrapped, also when built from `whenLoaded()`), or a `Closure` that is only evaluated when the relationship is actually rendered.

Instead of a `Relationship`, a `relationships` entry may also be a plain array (passed through as-is), a `Closure` returning either, or `null`/`MissingValue` (skipped).

#### Options

| Method                                              | Description                                                                                  |
|-----------------------------------------------------|----------------------------------------------------------------------------------------------|
| `type(string\|Closure)`                             | Type; a `Closure` receives each item — for polymorphic relations                             |
| `one()` / `many()`                                  | Force cardinality                                                                            |
| `data(mixed)`                                       | Replace the related data                                                                     |
| `identifyUsing(callable)`                           | Custom id per related item, e.g. `fn ($key) => Hashids::encode($key)`                        |
| `identifierMeta(array\|Closure)`                    | `meta` on each identifier, e.g. pivot data: `fn ($tag) => ['position' => $tag->pivot->position]` |
| `self($href, $meta = [])` / `related($href, $meta = [])` | Relationship links (`{ href }`, or `{ href, meta }`)                                    |
| `link($name, $href, $meta = [])` / `links([...])`   | Any link; `$href` may be a string, a ready-made array, a `Closure` or `null` (dropped)       |
| `meta(array\|Closure)`                              | Relationship-level meta (merged across calls)                                                |
| `withCount($count = null, $key = 'count')`          | Adds a count to meta; without `$count` the loaded to-many data is counted                    |
| `withoutData()`                                     | Never render `data`, only links/meta                                                         |
| `omitWhenMissing()`                                 | Omit the relationship when its data is missing, even with links/meta                        |
| `when(bool\|Closure)` / `unless(bool\|Closure)`     | Render conditionally (e.g. based on authorization)                                           |
| `relatedItems()`                                    | The related items as a collection — e.g. to build an `included` array yourself               |

#### App-wide conventions

Every `$this->relationship(...)` call goes through `newRelationship()`. Override it in a base resource to apply defaults or return your own `Relationship` subclass (all rendering steps — `identifier()`, `formatLink()`, `resolveMeta()` … — are protected and overridable):

```php
abstract class ApiResource extends JsonResource
{
    use BuildsApiResource;

    protected function newRelationship(mixed $data, string|Closure|null $type): Relationship
    {
        return new AppRelationship($data, $type);
    }
}
```

### Collections — `ApiResourceCollection`

Because `BuildsApiResource` overrides the static `collection()` method Laravel already gives every `JsonResource`, you don't need a dedicated collection class — just call it on your resource:

```php
return OrderResource::collection(Order::paginate());
```

This returns an `ApiResourceCollection` (an `AnonymousResourceCollection` under the hood) that wraps the items in a `data` key and — exactly like Laravel's own paginated resource responses — adds `links`/`meta` automatically for both `Illuminate\Pagination\LengthAwarePaginator` and cursor paginators (`Illuminate\Contracts\Pagination\CursorPaginator`). Passing a plain collection instead of a paginator just returns `{ "data": [...] }`, no extra setup required.

If you need a dedicated collection class (e.g. to add extra top-level `meta`), extend `ApiResourceCollection` directly and pass both the resource and the item class it collects into:

```php
use Zhylon\ApiResources\Http\Resources\ApiResourceCollection;

class OrderCollection extends ApiResourceCollection
{
    public function __construct($resource)
    {
        parent::__construct($resource, OrderResource::class);
    }
}
```

```php
return new OrderCollection(Order::paginate());
```

## Testing

```bash
composer test
```

## License

The MIT License (MIT). See [LICENSE](LICENSE) for more information.
