<?php

declare(strict_types=1);

namespace Zhylon\ApiResources\Http\Resources;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\MissingValue;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ApiResourceCollection extends AnonymousResourceCollection
{
    /** @var list<array|Closure> */
    protected array $apiTopLevelMeta = [];

    protected array $apiTopLevelLinks = [];

    public function toArray(Request $request): array
    {
        // Items are resolved eagerly (instead of during JSON encoding), so their relationships are
        // known when `with()` builds the top-level `included` array.
        return [
            'data' => $this->collection
                ->map(fn ($item) => $item instanceof JsonResource ? $item->resolve($request) : $item)
                ->all(),
        ];
    }

    /**
     * Adds top-level `meta`, merged with the pagination meta. A `Closure` is evaluated when rendered.
     */
    public function withMeta(array|Closure $meta): static
    {
        $this->apiTopLevelMeta[] = $meta;

        return $this;
    }

    /**
     * Adds top-level `links` (formatted as `{ href }`), merged with the pagination links.
     *
     * @param  array<string, string|array|Closure|MissingValue|null>  $links
     */
    public function withLinks(array $links): static
    {
        $this->apiTopLevelLinks = [...$this->apiTopLevelLinks, ...$links];

        return $this;
    }

    /**
     * Adds `included`, `links` and `meta`. If you override `with()` in a subclass, merge
     * `$this->apiTopLevel($request)` into its result to keep them.
     */
    public function with($request)
    {
        return array_merge_recursive(parent::with($request), $this->apiTopLevel($request));
    }

    public function apiTopLevel(Request $request): array
    {
        return TopLevel::members(
            CompoundDocument::included($this->collection),
            $this->apiTopLevelLinks,
            $this->apiTopLevelMeta,
        );
    }

    public function paginationInformation(Request $request, array $paginated, array $default): array
    {
        if ($this->resource instanceof CursorPaginator) {
            $paginator = $this->resource;

            return [
                'links' => [
                    'prev' => $paginator->previousPageUrl(),
                    'next' => $paginator->nextPageUrl(),
                ],
                'meta' => [
                    'per_page'    => $paginator->perPage(),
                    'path'        => $paginator->path(),
                    'next_cursor' => $paginator->nextCursor()?->encode(),
                    'prev_cursor' => $paginator->previousCursor()?->encode(),
                ],
            ];
        }

        $paginator = $this->resource;

        return [
            'links' => [
                'first' => $paginator->url(1),
                'prev'  => $paginator->previousPageUrl(),
                'next'  => $paginator->nextPageUrl(),
                'last'  => $paginator->url($paginator->lastPage()),
            ],
            'meta' => [
                'total'        => $paginator->total(),
                'per_page'     => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'path'         => $paginator->path(),
            ],
        ];
    }
}
