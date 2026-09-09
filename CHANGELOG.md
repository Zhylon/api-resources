# Changelog

All notable changes to `zhylon/api-resources` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/Zhylon/api-resources/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/Zhylon/api-resources/compare/v0.2.0...v1.0.0
[0.2.0]: https://github.com/Zhylon/api-resources/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/Zhylon/api-resources/releases/tag/v0.1.0
