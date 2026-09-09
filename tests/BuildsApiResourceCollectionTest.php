<?php

declare(strict_types=1);

use Illuminate\Pagination\LengthAwarePaginator;
use Zhylon\ApiResources\Tests\Fixtures\PlainModel;
use Zhylon\ApiResources\Http\Resources\ApiResourceCollection;
use Zhylon\ApiResources\Tests\Fixtures\OrderResourceWithoutTimestamps;

it('returns an ApiResourceCollection without a dedicated collection class', function () {
    $models = [
        new PlainModel(id: 1, status: 'paid'),
        new PlainModel(id: 2, status: 'pending'),
    ];

    $collection = OrderResourceWithoutTimestamps::collection($models);

    expect($collection)->toBeInstanceOf(ApiResourceCollection::class);
});

it('wraps each item through toApiResource and preserves pagination meta', function () {
    $paginator = new LengthAwarePaginator(
        items: [
            new PlainModel(id: 1, status: 'paid'),
            new PlainModel(id: 2, status: 'pending'),
        ],
        total: 2,
        perPage: 2,
        currentPage: 1,
        options: ['path' => '/orders'],
    );

    $response = OrderResourceWithoutTimestamps::collection($paginator)->response(request())->getData(true);

    expect($response['data'])->toBe([
        [
            'id'         => 1,
            'type'       => 'order',
            'attributes' => ['status' => 'paid'],
            'links'      => ['self' => ['href' => null]],
        ],
        [
            'id'         => 2,
            'type'       => 'order',
            'attributes' => ['status' => 'pending'],
            'links'      => ['self' => ['href' => null]],
        ],
    ])->and($response['meta'])->toMatchArray([
        'total'        => 2,
        'per_page'     => 2,
        'current_page' => 1,
    ]);
});
