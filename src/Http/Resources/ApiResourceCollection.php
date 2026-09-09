<?php

declare(strict_types=1);

namespace Zhylon\ApiResources\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ApiResourceCollection extends AnonymousResourceCollection
{
    public function toArray(Request $request): array
    {
        return [
            'data' => $this->collection,
        ];
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
