<?php

declare(strict_types=1);

use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Zhylon\ApiResources\Http\Resources\ApiResourceCollection;

it('wraps items in a data key and adds length-aware pagination meta', function () {
    $paginator = new LengthAwarePaginator(
        items: [['id' => 1], ['id' => 2]],
        total: 5,
        perPage: 2,
        currentPage: 1,
        options: ['path' => '/orders'],
    );

    $response = (new ApiResourceCollection($paginator, null))->response(request())->getData(true);

    expect($response['data'])->toBe([['id' => 1], ['id' => 2]])
        ->and($response['meta'])->toBe([
            'total'        => 5,
            'per_page'     => 2,
            'current_page' => 1,
            'last_page'    => 3,
            'path'         => '/orders',
        ])
        ->and($response['links'])->toHaveKeys(['first', 'prev', 'next', 'last'])
        ->and($response['links']['first'])->toBe('/orders?page=1');
});

it('adds cursor pagination meta for cursor paginators', function () {
    $paginator = new CursorPaginator(
        items: [['id' => 1], ['id' => 2]],
        perPage: 2,
        cursor: null,
        options: ['path' => '/orders', 'parameters' => ['id']],
    );

    $response = (new ApiResourceCollection($paginator, null))->response(request())->getData(true);

    expect($response['data'])->toBe([['id' => 1], ['id' => 2]])
        ->and($response['meta'])->toMatchArray([
            'per_page' => 2,
            'path'     => '/orders',
        ])
        ->and($response['links'])->toHaveKeys(['prev', 'next']);
});
