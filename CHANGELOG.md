# Changelog

All notable changes to `zhylon/api-resources` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - 2026-11-17

### Added

- **Relationships:** `relationships` parameter for `toApiResource()` and a fluent `Relationship` builder:
  to-one/to-many resource linkage (auto-detected or forced cardinality), `self`/`related`/custom links
  (optionally with link meta), relationship meta, per-identifier meta (e.g. pivot data), counts,
  polymorphic types via closures, custom id resolution, conditional rendering, links-only relationships
  and lazy data. Relations that aren't loaded are left out, loaded empty ones render `data: null` /
  `data: []`. `BuildsApiResource::relationship()` reads loaded Eloquent relations (incl. dot notation),
  `newRelationship()` is the factory for app-wide conventions.
- **Included resources (compound documents):** `Relationship::resource()` renders related items through
  their resource — identifiers take `id`/`type` from it, and the full objects are added to a top-level
  `included` array, recursively, de-duplicated by `type` + `id` and without repeating primary data.
  The resource class is detected automatically from `JsonResource` data. `included()` /
  `withoutIncluded()` control it per relationship; `Relationship::maxDepth()` guards against cycles.
- **Resource-level `links` and `meta`:** new `links` and `meta` parameters for `toApiResource()`.
- **Top-level `meta` and `links`:** `withMeta()` / `withLinks()` on single resources and on
  `ApiResourceCollection`, merged with pagination info and `additional()`.
- **Sparse fieldsets:** `SparseFieldsets::enable()` (opt-in) limits `attributes` and `relationships` per
  type via `?fields[type]=a,b`; custom parameter names and resolvers are supported.
- **Error responses:** `ApiExceptionRenderer` (register via `$exceptions->render(...)`) renders validation,
  authentication, authorization, not-found, other HTTP and server errors as `{ "errors": [...] }`, with
  JSON pointers, rule codes, preserved HTTP headers and debug details only in debug mode. Customizable via
  `when()`, `map()`, `pointerPrefix()`, `pointerUsing()`, `withRuleCodes()`, `debug()` and the
  `ProvidesApiErrors` interface. `ApiError` and `ApiErrorResponse` can also be used directly.
- `ResourceId` with the id resolution chain shared by resources and relationship identifiers, plus
  `ResourceId::resolveUsing()` for an app-wide resolver.
- `Link` helper used for all links (`{ href }`, optionally with `meta`).
- `timestamps` accepts a list of date attributes (e.g. `['created_at', 'deployed_at', 'deleted_at']`), formatted
  by the overridable `formatTimestamp()`.
- `ResourceId::castToString()` (opt-in) renders all resource and relationship ids as strings, as JSON:API requires.
- PHPStan (Larastan, level 8) and 100 % test coverage, enforced in CI. CI now also runs PHP 8.3 and 8.5, a
  lowest-dependencies job and a separate Pint/PHPStan job. Composer scripts `test-coverage`, `analyse`,
  `lint` and `format`. Pest 4 is supported (Pest 3 is kept for PHP 8.2).
- `Closure` values in `attributes` are evaluated when rendered (and skipped when filtered out by a
  sparse fieldset).
- **Laravel Boost:** package guidelines (`resources/boost/guidelines/core.blade.php`) and the upgrade skill
  `zhylon-api-resources-upgrade`, installed via `php artisan boost:install` / `boost:update`.
- `UPGRADING.md` with the 1.x → 2.0 upgrade guide, including a prompt for AI agents.
- `SECURITY.md` with the vulnerability reporting process (security@zhylon.net) and the supported-versions policy.
- `.gitattributes` keeping tests and tooling out of the dist archive.

### Changed

- **Breaking (output):** without `selfHref`, resources no longer render `"links": { "self": { "href": null } }`.
  `links` is left out entirely when no link is set.
- **Breaking:** `ApiResourceCollection::toArray()` resolves its items eagerly, so `data` is a plain array
  instead of a collection of resources. Required to build `included` before the response is encoded.
- **Breaking:** `BuildsApiResource` now defines `with()`, `withMeta()`, `withLinks()`, `apiTopLevel()`,
  `apiDocument()` and `apiIncludedResources()`. A resource that overrides `with()` has to merge
  `$this->apiTopLevel($request)` into its result to keep `included` and top-level `meta`/`links`.
- **Breaking:** `Closure` attribute values are evaluated instead of being serialized as `{}`.
- **Breaking:** `BuildsApiResource::resolveResourceId()` delegates to `ResourceId::resolve()`. Behaviour is
  unchanged for objects, but a `null` resource now resolves to a `null` id instead of throwing a `TypeError`.
- `BuildsApiResource::collection()` reads `$preserveKeys` via reflection instead of instantiating the resource.

### Fixed

- `Relationship::relatedItems()` with a forced `many()` and a single model returned the model's attributes
  instead of the model.
- `withCount()` counted `0` for generator data, because rendering had already consumed the generator.

## [1.0.0] - 2026-09-09

### Removed

- Laravel 10.x and 11.x support, added in v0.2.0 as a temporary, untested widening. `composer.json` now
  requires `^12.0 || ^13.0` again, matching what's actually covered by the test suite and CI.

## [0.2.0] - 2026-09-09

### Added

- Best-effort support for Laravel 10.x and 11.x by widening the `illuminate/*` constraints in
  `composer.json`. Not covered by the test suite or CI — dependency resolution was verified manually.
  Removed again in v1.0.0.

## [0.1.0] - 2026-09-09

### Added

- `BuildsApiResource` trait for `JsonResource` classes: builds a `{ id, type, attributes, links }`
  payload via `toApiResource()`, with configurable `id` resolution (`safeHashID()` →
  `TobyMaxham\HashId\Interfaces\Hashing::hashID()` → `getKey()`/`id`, overridable via
  `resolveResourceId()` or an explicit `id` argument).
- `BuildsApiResource::collection()` override so `YourResource::collection(...)` returns an
  `ApiResourceCollection` directly, without a dedicated collection class.
- `ApiResourceCollection`, an `AnonymousResourceCollection` subclass that wraps items in a `data` key
  and adds `links`/`meta` pagination info for both `LengthAwarePaginator` and cursor paginators.
- Laravel 12.x and 13.x support on PHP 8.2+, with a Pest test suite and a GitHub Actions matrix
  covering both.

[Unreleased]: https://github.com/Zhylon/api-resources/compare/v2.0.0...HEAD
[2.0.0]: https://github.com/Zhylon/api-resources/compare/v1.0.0...v2.0.0
[1.0.0]: https://github.com/Zhylon/api-resources/compare/v0.2.0...v1.0.0
[0.2.0]: https://github.com/Zhylon/api-resources/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/Zhylon/api-resources/releases/tag/v0.1.0
