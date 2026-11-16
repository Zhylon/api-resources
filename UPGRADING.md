# From 1.x to 2.0

Version 2.0 adds relationships, included resources, resource-level and top-level `links`/`meta`, sparse fieldsets and error responses. There's exactly one change to the output of existing resources: [no more `"self": { "href": null }`](#no-more-empty-self-links). All other breaking changes only affect code that overrides or extends the package. Most apps only need the checklist.

## Upgrade with an AI agent

The package ships step by step upgrade instructions for AI agents as [Agent Skill](resources/boost/skills/zhylon-api-resources-upgrade/SKILL.md).
The agent searches your code, asks you before decisions, updates the package and the code, runs your tests and does not commit.

Paste this prompt into Claude Code, Cursor, Copilot or a similar agent:

```text
Upgrade zhylon/api-resources from 1.x to 2.0. Follow the instructions in
https://raw.githubusercontent.com/Zhylon/api-resources/main/resources/boost/skills/zhylon-api-resources-upgrade/SKILL.md
```

With [Laravel Boost](https://laravel.com/docs/boost), the skill and the package guidelines are installed with `php artisan boost:install` / `boost:update` once the package is on 2.x. Then ask your agent to "upgrade zhylon/api-resources".

Always review the changes before you deploy them.

## Checklist

1. Run `composer require zhylon/api-resources:^2.0`.
2. Search for **`toApiResource()` calls without `selfHref`** and check whether API clients or tests rely on `links.self.href` being `null` (see [below](#no-more-empty-self-links)).
3. Search for resources using `BuildsApiResource` that override **`with()`** and merge `$this->apiTopLevel($request)` (see [below](#with-is-defined-by-the-trait)).
4. Search for classes extending **`ApiResourceCollection`** that override `toArray()` or `with()`, or read `data` as a collection.
5. Search for **method name collisions** in your resources (see [list](#new-methods-in-buildsapiresource)).
6. Search for **`Closure` values in `attributes`** of `toApiResource()`.
7. Run your tests.

Requirements are unchanged: PHP 8.2+, Laravel 12 or 13.

## No more empty `self` links

Without `selfHref`, 1.x rendered a `self` link with a `null` href. 2.0 leaves it out, and leaves out `links` entirely if no other links are set:

```json
// 1.x
{ "id": 1, "type": "order", "attributes": {...}, "links": { "self": { "href": null } } }

// 2.0
{ "id": 1, "type": "order", "attributes": {...} }
```

Clients that read `links.self.href` without checking for its existence break. Either pass a `selfHref` (recommended, it's useful for clients anyway), or check your clients and tests. This also applies to related resources in `included`.

## `with()` is defined by the trait

`BuildsApiResource` now implements `with()` to add `included` and the top-level `meta`/`links`. If your resource defines its own `with()`, yours wins and these members are silently dropped. Merge them:

```php
// before
public function with($request): array
{
    return ['meta' => ['version' => '1']];
}

// after
public function with($request): array
{
    return array_merge_recursive(['meta' => ['version' => '1']], $this->apiTopLevel($request));
}

// or, simpler: remove with() and use
return OrderResource::make($order)->withMeta(['version' => '1']);
```

The same applies to subclasses of `ApiResourceCollection` that override `with()`.

## `ApiResourceCollection` resolves its items eagerly

`toArray()` returns `['data' => [...]]` with already rendered arrays (before: a collection of resource objects that was rendered while encoding the JSON). This is needed to build `included` before the response is sent. The JSON output is the same.

Only relevant if a subclass or a test reads `toArray()['data']` and expects resource objects or a `Collection`.

## `Closure` values in `attributes` are evaluated

```php
'total' => fn () => $this->calculateTotal(),
```

Before, a closure was serialized as `{}`. Now it's called when the attribute is rendered — and not at all when a sparse fieldset filters it out. If you relied on `{}` (unlikely), remove the closure.

## `null` resources

`resolveResourceId()` now delegates to `ResourceId::resolve()`. Objects resolve exactly as before. A `null` resource (or a scalar) used to throw a `TypeError`, now it resolves to `null` (or the scalar itself).

## New methods in `BuildsApiResource`

Public: `with()`, `withMeta()`, `withLinks()`, `apiTopLevel()`, `apiDocument()`, `apiIncludedResources()`.
Protected: `relationship()`, `newRelationship()`, `loadedRelation()`, `resolveRelationships()`, `sparseFieldsFor()`.
Properties: `$apiIncluded`, `$apiDocument`, `$apiTopLevelMeta`, `$apiTopLevelLinks`.

If your resources already have methods with these names, your methods win over the trait. Rename them, if they do something else.

`toApiResource()` got the new parameters `relationships`, `links` and `meta`, and `timestamps` also accepts a list of attributes. Calls with positional or named arguments keep working. The trait also got the protected method `formatTimestamp()`.

## Optional: use the new features

None of these are required, but they replace code many apps wrote by hand:

- **Hand-written relationships**, e.g. `[...$this->toApiResource(...), 'relationships' => [...]]` → `relationships: [...]` with `Relationship` (see readme).
- **`additional(['included' => ...])`** → `->resource(SiteResource::class)` on the relationship.
- **Custom JSON error handling** in `bootstrap/app.php` → `$exceptions->render(new ApiExceptionRenderer)`. This changes the error format of your API, so check your API clients first.
- **`?fields[type]=...` handling** → `SparseFieldsets::enable()`.
- **String ids** as required by JSON:API → `ResourceId::castToString()`. **Changes the type of integer ids for all API clients.**
- **Hand-formatted date attributes** (`deleted_at`, `deployed_at` …) → `timestamps: ['created_at', 'updated_at', 'deleted_at']`.
