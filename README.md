[![Zhylon API-Resources][api-resources-image]][api-resources-edit-link]

[![Latest Version on Packagist](https://img.shields.io/packagist/v/zhylon/api-resources.svg?style=flat-square)](https://packagist.org/packages/zhylon/api-resources)
[![Tests](https://img.shields.io/github/actions/workflow/status/Zhylon/api-resources/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/Zhylon/api-resources/actions/workflows/tests.yml)
[![PHP Version](https://img.shields.io/packagist/dependency-v/zhylon/api-resources/php?style=flat-square)](https://packagist.org/packages/zhylon/api-resources)
[![Laravel Version](https://img.shields.io/packagist/dependency-v/zhylon/api-resources/illuminate%2Fsupport?label=laravel&style=flat-square)](https://packagist.org/packages/zhylon/api-resources)
[![MIT Licensed](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE)
[![Total Downloads](https://img.shields.io/packagist/dt/zhylon/api-resources.svg?style=flat-square)](https://packagist.org/packages/zhylon/api-resources)

Small, opinionated helpers for building consistent, JSON:API-inspired API responses in Laravel:

- `BuildsApiResource` — a trait for `JsonResource` classes that produces a `{ id, type, attributes, relationships, links, meta }` payload, and makes `YourResource::collection(...)` return an enveloped, paginated collection automatically.
- `Relationship` — a fluent builder for `relationships` entries that only renders what your app has loaded, and puts related resources into a top-level `included` array.
- `ApiResourceCollection` — the collection behind that: wraps items in `data`, adds pagination `links`/`meta`, `included`, and your own top-level `meta`/`links`.
- `SparseFieldsets` — opt-in support for `?fields[type]=a,b`.
- `ApiExceptionRenderer`, `ApiError`, `ApiErrorResponse` — consistent `{ "errors": [...] }` responses for validation, auth, HTTP and server errors.

The package renders; **your app decides what to load** (e.g. based on an `?include=` parameter, via `with()`/`load()` or `spatie/laravel-query-builder`'s `allowedIncludes()`). There's no query parsing and no hidden database access.

## Requirements

- PHP 8.2+
- Laravel 12.x or 13.x

**Upgrading from 1.x?** Read the [upgrade guide](UPGRADING.md) first, or let your [AI agent do the upgrade](UPGRADING.md#upgrade-with-an-ai-agent).

## Installation

```bash
composer require zhylon/api-resources
```

There is nothing to publish — the classes are used directly. Optional app-wide settings (id resolver, sparse fieldsets, exception renderer) are one-liners, shown below.

### Laravel Boost

The package ships [Laravel Boost](https://laravel.com/docs/boost) guidelines, so AI agents in your app build resources, relationships and error responses the way this package expects, and an upgrade skill for major versions. Run `php artisan boost:install` (or `boost:update`) after installing the package.

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
    "data": {
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
}
```

`toApiResource()` accepts:

| Parameter       | Type                  | Description                                                                         |
|-----------------|-----------------------|-------------------------------------------------------------------------------------|
| `type`          | `string`              | The JSON:API-style resource type, e.g. `'order'`                                    |
| `attributes`    | `array`               | The resource's own attributes. `Closure` values are only evaluated when rendered   |
| `selfHref`      | `?string`             | Value for `links.self.href`                                                         |
| `timestamps`    | `bool`                | Appends `created_at`/`updated_at` (as ISO-8601 strings) to `attributes`             |
| `id`            | `string\|int\|null`   | Overrides automatic id resolution (see below)                                       |
| `relationships` | `array`               | See [Relationships](#relationships)                                                 |
| `links`         | `array`               | More links next to `self`, e.g. `['dashboard' => $url]`. `null` links are dropped   |
| `meta`          | `array\|Closure`      | Resource-level meta, e.g. permissions: `['can' => ['update' => ...]]`               |

Laravel's conditional helpers work everywhere (`$this->when(...)`, `$this->whenLoaded(...)` …).

#### Resolving the `id`

When `id` isn't passed explicitly, the id is resolved via `ResourceId::resolve()` in this order:

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
protected function resolveResourceId(): string|int|null
{
    return $this->resource->uuid;
}
```

### Relationships

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
                // only rendered if `site` is loaded — id and type come from SiteResource,
                // and the full site ends up in the top-level `included` array
                'site' => $this->relationship('site')->resource(SiteResource::class),

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
    "data": {
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
    },
    "included": [
        { "id": "s1", "type": "site", "attributes": { "name": "example.com" }, "links": { "self": { "href": "..." } } }
    ]
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

Without a resource class, identifiers use `ResourceId::resolve()` (see above); scalars are used as the id directly.

#### Creating relationships

| Call                                                     | Description                                                                 |
|----------------------------------------------------------|-----------------------------------------------------------------------------|
| `$this->relationship('site', 'site')`                    | Data from the Eloquent relation, missing if not loaded                      |
| `$this->relationship('site.server', 'server')`           | Nested loaded relations via dot notation                                    |
| `$this->relationship('site', 'site', $this->site_id)`    | Explicit data (foreign key, model, collection, `Closure` …)                 |
| `Relationship::toOne('site', $data)`                     | Forced to-one, independent of an Eloquent relation                          |
| `Relationship::toMany('tag', $data)`                     | Forced to-many (`null` → `[]`)                                              |
| `Relationship::make($data, 'tag')`                       | Cardinality detected (collections/iterables/list arrays → to-many)          |
| `Relationship::make(SiteResource::make($this->whenLoaded('site')))` | Resource class taken from the data — no `type` needed            |
| `Relationship::linksOnly(['related' => $url])`           | No resource linkage at all, only links                                      |

Data may be models, plain objects, arrays, scalar ids, iterables, `JsonResource`s and resource collections (unwrapped, also when built from `whenLoaded()`), or a `Closure` that is only evaluated when the relationship is actually rendered.

Instead of a `Relationship`, a `relationships` entry may also be a plain array (passed through as-is), a `Closure` returning either, or `null`/`MissingValue` (skipped).

#### Options

| Method                                              | Description                                                                                  |
|-----------------------------------------------------|----------------------------------------------------------------------------------------------|
| `type(string\|Closure)`                             | Type; a `Closure` receives each item — for polymorphic relations. Optional with `resource()` |
| `resource(class-string\|Closure)`                   | Render items through this resource (id/type from it, full object into `included`). A `Closure` receives the item and returns a resource, e.g. per morph type |
| `included(bool\|Closure)` / `withoutIncluded()`     | Whether rendered items go into `included` (default: yes, whenever a resource is known)       |
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
| `relatedItems()`                                    | The related items as a collection                                                            |

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

### Included resources (compound documents)

`relationships` only carries `{ id, type }` pointers. With `->resource(...)`, the full related objects are added once to a top-level `included` array, so a client gets e.g. 20 deployments *and* their sites in a single request — each site only once, even if every deployment points to the same one.

- Only **loaded** relations end up in `included`, so `?include=site,site.server` maps directly to `->with(['site', 'site.server'])` in your controller.
- Nested relations work automatically, as long as the related resource defines its relationships the same way.
- Entries are de-duplicated by `type` + `id`, and resources that are already part of the primary `data` are never repeated in `included`.
- Rendering is guarded against cyclic relations (e.g. via `chaperone()`): after 16 nesting levels a `LogicException` explains what's going on. Adjust with `Relationship::maxDepth()`.
- If your resource overrides `with()`, merge `$this->apiTopLevel($request)` into its result to keep `included` (and the top-level `meta`/`links` below).

### Collections — `ApiResourceCollection`

Because `BuildsApiResource` overrides the static `collection()` method Laravel already gives every `JsonResource`, you don't need a dedicated collection class — just call it on your resource:

```php
return OrderResource::collection(Order::paginate());
```

This returns an `ApiResourceCollection` (an `AnonymousResourceCollection` under the hood) that wraps the items in a `data` key and — exactly like Laravel's own paginated resource responses — adds `links`/`meta` automatically for both `Illuminate\Pagination\LengthAwarePaginator` and cursor paginators (`Illuminate\Contracts\Pagination\CursorPaginator`). Passing a plain collection instead of a paginator just returns `{ "data": [...] }`, no extra setup required.

If you need a dedicated collection class, extend `ApiResourceCollection` directly and pass both the resource and the item class it collects into:

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

### Top-level `meta` and `links`

Both single resources and collections accept top-level `meta`/`links`, e.g. applied filters or the sort order. They are merged with the pagination info and with Laravel's `additional()`:

```php
return OrderResource::collection(Order::paginate())
    ->withMeta(['filters' => $request->only('status'), 'sort' => '-created_at'])
    ->withMeta(fn () => ['generated_at' => now()->toAtomString()])
    ->withLinks(['docs' => 'https://docs.example.test/orders']);
```

### Sparse fieldsets

`?fields[order]=status,total&fields[site]=name` limits the `attributes` and `relationships` of each type (`id`, `type`, `links` and `meta` are always kept). Since `fields` might already mean something else in your app, it is **off by default** — enable it once:

```php
use Zhylon\ApiResources\Http\Resources\SparseFieldsets;

SparseFieldsets::enable();             // reads ?fields[type]=a,b
SparseFieldsets::enable('only');       // reads ?only[type]=a,b
SparseFieldsets::resolveUsing(fn (Request $request) => ['order' => ['status']]); // fully custom
```

Types without a fieldset stay unrestricted. Attributes given as `Closure`s are only evaluated when they're requested, so expensive values cost nothing when they're filtered out. A single resource can opt in or out by overriding `sparseFieldsFor(string $type): ?array`.

### Error responses

Register the renderer in `bootstrap/app.php`:

```php
use Zhylon\ApiResources\Http\Errors\ApiExceptionRenderer;

->withExceptions(function (Exceptions $exceptions) {
    $exceptions->render(new ApiExceptionRenderer);
})
```

Every exception on `api/*` (or for requests that expect JSON) is then rendered as:

```json
{
    "errors": [
        {
            "status": "422",
            "code": "required",
            "title": "Unprocessable Content",
            "detail": "The name field is required.",
            "source": { "pointer": "/name" }
        }
    ]
}
```

| Exception                                    | Result                                                                          |
|----------------------------------------------|---------------------------------------------------------------------------------|
| `ValidationException`                        | One error per message, failed rule as `code`, field as `source.pointer`         |
| `AuthenticationException`                    | 401                                                                             |
| `AuthorizationException`, `ModelNotFoundException`, other HTTP exceptions | Their status (403, 404, 429 …) incl. headers like `Retry-After`. Not-found errors don't leak the model class |
| Anything else                                | 500; message and debug details (`exception`, `file`, `line`, `trace` in `meta`) only with `app.debug` |
| `HttpResponseException`                      | Left untouched                                                                  |

Customization:

```php
$exceptions->render(
    (new ApiExceptionRenderer)
        ->when(fn (Request $request) => $request->is('api/*'))      // which requests
        ->pointerPrefix('/data/attributes')                          // for JSON:API request bodies
        ->pointerUsing(fn (string $field) => "/{$field}")            // or fully custom (null omits source)
        ->withRuleCodes(false)                                       // no `code` for validation errors
        ->debug(false)                                               // force debug details on/off
        ->map(PaymentFailed::class, fn (PaymentFailed $e) => ApiError::make(402, $e->getMessage())->code('payment_failed')),
);
```

Your own exceptions can also implement `ProvidesApiErrors`:

```php
class SiteLockedException extends RuntimeException implements ProvidesApiErrors
{
    public function toApiErrors(Request $request): ApiError
    {
        return ApiError::make(409, 'The site is locked.')
            ->code('site_locked')
            ->link('about', 'https://docs.example.test/errors/site-locked');
    }
}
```

And error responses can be returned directly from controllers:

```php
return ApiErrorResponse::make(
    ApiError::make(409, 'The site is locked.')->code('site_locked'),
)->meta(['request_id' => $requestId]);
```

`ApiError` supports `id`, `status`, `code`, `title` (defaults to the HTTP status text), `detail`, `pointer()`/`parameter()`/`header()` (as `source`), `link()` and `meta()`. With several errors, the response status is their shared status, otherwise `400` for mixed 4xx and `500` for anything else — or set it via `status()`.

## Upgrading

See [UPGRADING.md](UPGRADING.md). The breaking changes of 2.0 only affect code that overrides or extends the package — the output of existing resources stays the same.

## Security

- **Authorization is up to your app.** Everything that is loaded is rendered — in `relationships` and in `included`. Check access before you load a relation (or use `Relationship::when()`), and only allow known values for `?include=`.
- **Sparse fieldsets are no access control.** They only shape the output; hide sensitive attributes in the resource itself.
- **Error responses don't leak internals** outside debug mode: server errors have no message, not-found errors don't contain the model class. With `app.debug` on, they contain messages, files and stack traces — never enable it in production.
- **Laravel Octane:** supported. The global settings (`ResourceId::resolveUsing()`, `SparseFieldsets::enable()`, `Relationship::maxDepth()`) are meant to be set once at boot and keep no request state.

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Security Vulnerabilities

Please review [our security policy](SECURITY.md) on how to report security vulnerabilities — in short: use GitHub's private vulnerability reporting or mail [security@zhylon.net](mailto:security@zhylon.net), not the issue tracker.

## Credits

- [TobyMaxham](https://github.com/TobyMaxham)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). See [LICENSE](LICENSE) for more information.


[api-resources-image]: https://socialify.git.ci/Zhylon/api-resources/image?description=1&font=Raleway&issues=1&language=1&owner=1&pattern=Charlie%20Brown&pulls=1&stargazers=1&theme=Light
[api-resources-edit-link]: https://socialify.git.ci/Zhylon/api-resources?description=1&font=Raleway&issues=1&language=1&owner=1&pattern=Charlie%20Brown&pulls=1&stargazers=1&theme=Light
