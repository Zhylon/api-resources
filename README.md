# Zhylon API-Resources

[![Latest Stable Version](http://poser.pugx.org/zhylon/api-resources/v)](https://packagist.org/packages/zhylon/api-resources)
[![Tests](https://github.com/Zhylon/api-resources/actions/workflows/tests.yml/badge.svg)](https://github.com/Zhylon/api-resources/actions/workflows/tests.yml)
[![License](http://poser.pugx.org/zhylon/api-resources/license)](https://packagist.org/packages/zhylon/api-resources)

Small, opinionated helpers for building consistent, JSON:API-inspired API resource responses in Laravel:

- `BuildsApiResource` — a trait for `JsonResource` classes that produces a `{ id, type, attributes, links }` payload, and makes `YourResource::collection(...)` return an enveloped, paginated collection automatically.
- `ApiResourceCollection` — the `AnonymousResourceCollection` subclass behind that: wraps items in `data` and adds `links`/`meta` pagination info for both length-aware and cursor paginators.

## Requirements

- PHP 8.2+
- Laravel 12.x or 13.x, actively tested in CI (see the [test matrix](.github/workflows/tests.yml))
- Laravel 10.x and 11.x are also allowed by `composer.json` and resolve cleanly, but are **not** covered by the test suite or CI — best-effort only. This is a deliberate, temporary widening for v0.2; support for 10.x/11.x will be dropped again in v1.0.

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

#### Resolving the `id`

When `id` isn't passed explicitly, `resolveResourceId()` resolves it in this order:

1. `safeHashID()` on the underlying resource, if it defines one.
2. `hashID()`, if the underlying resource implements `TobyMaxham\HashId\Interfaces\Hashing`.
3. The resource's primary key (`getKey()`), or its `id` property as a last resort.

None of this requires [`tobymaxham/laravel-hashid`](https://github.com/TobyMaxham/laravel-hashid) (or any other hashid package) to be installed — the checks use `method_exists()`/`interface_exists()`, so they simply no-op down the chain when unavailable.

For full custom control, override `resolveResourceId()` in your resource class:

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
