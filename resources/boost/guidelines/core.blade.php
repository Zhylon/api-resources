## Zhylon API-Resources (zhylon/api-resources)

This package builds JSON:API-inspired API responses: `{ id, type, attributes, relationships, links, meta }` resources, paginated collections, a top-level `included` array and `{ errors: [...] }` error responses.

- API resources extend `JsonResource`, `use \Zhylon\ApiResources\Http\Resources\BuildsApiResource;` and return `$this->toApiResource(type: ..., attributes: [...], selfHref: ..., relationships: [...], links: [...], meta: [...])` from `toArray()`. Do not build the `id`/`type`/`attributes` structure by hand. Always pass a `selfHref` if the resource has a show route.
- Return lists with `YourResource::collection($query->paginate())`. Do not create collection classes unless they add behavior; extend `\Zhylon\ApiResources\Http\Resources\ApiResourceCollection` if you need one.
- Never set `id` manually unless needed. It is resolved by `\Zhylon\ApiResources\Http\Resources\ResourceId` (`safeHashID()` → `hashID()` → `getKey()`), app-wide overridable with `ResourceId::resolveUsing()`.
- Relationships: use `$this->relationship('site')->resource(SiteResource::class)` (id/type from the resource, full object in `included`), `$this->relationship('tags', 'tag')` (identifiers only) or `$this->relationship('server', 'server', $this->server_id)` (identifier from the foreign key, no query). Options: `related()`, `self()`, `meta()`, `withCount()`, `identifierMeta()`, `withoutIncluded()`, `when()`.
- Resources only render relations that are loaded. **Loading is the job of the controller/query**, e.g. from `?include=` with `->with([...])` or `spatie/laravel-query-builder`'s `allowedIncludes()`. Never parse `include` or query the database inside a resource. Never lazy load relations inside `toArray()`.
- Check authorization before loading a relation: everything that is loaded is rendered in `relationships` and `included`.
- Put permissions and similar data in `meta: [...]` of `toApiResource()`, response-wide data (filters, sort) in `->withMeta([...])` / `->withLinks([...])` on the resource or collection. Do not use `additional()` for that.
- A resource that overrides `with()` must merge `$this->apiTopLevel($request)`, otherwise `included` and top-level meta/links are lost.
- Date attributes go into `timestamps: true` (created_at/updated_at) or `timestamps: ['created_at', 'deployed_at']`, not into `attributes`; they are formatted by `formatTimestamp()`.
- Expensive attributes can be closures (`'total' => fn () => ...`); they are only evaluated when rendered.
- Sparse fieldsets (`?fields[type]=a,b`) are opt-in via `\Zhylon\ApiResources\Http\Resources\SparseFieldsets::enable()`. They are no access control.
- Errors: the app registers `$exceptions->render(new \Zhylon\ApiResources\Http\Errors\ApiExceptionRenderer)` in `bootstrap/app.php`. Throw normal Laravel exceptions (`ValidationException`, `abort(404)`, `AuthorizationException`) instead of building error JSON. For custom errors return `ApiErrorResponse::make(ApiError::make(409, 'detail')->code('machine_code'))` or implement `\Zhylon\ApiResources\Http\Errors\ProvidesApiErrors` on the exception.
- In tests, assert the structure (`data.id`, `data.type`, `data.relationships.site.data`, `included`, `errors.0.code`) and do not hard code hashed ids.
- For upgrades between major versions use the skill `zhylon-api-resources-upgrade`.
