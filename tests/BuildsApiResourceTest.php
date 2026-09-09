<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Zhylon\ApiResources\Tests\Fixtures\PlainModel;
use Zhylon\ApiResources\Tests\Fixtures\HashingModel;
use Zhylon\ApiResources\Tests\Fixtures\KeylessModel;
use Zhylon\ApiResources\Tests\Fixtures\OrderResource;
use Zhylon\ApiResources\Tests\Fixtures\SafeHashIdModel;
use Zhylon\ApiResources\Tests\Fixtures\OrderResourceWithoutTimestamps;

it('builds the JSON:API-ish structure with timestamps', function () {
    $model = new PlainModel(
        id: 1,
        status: 'paid',
        created_at: Carbon::parse('2026-01-01T00:00:00Z'),
        updated_at: Carbon::parse('2026-01-02T00:00:00Z'),
    );

    $array = (new OrderResource($model))->toArray(request());

    expect($array)->toBe([
        'id'         => 1,
        'type'       => 'order',
        'attributes' => [
            'status'     => 'paid',
            'created_at' => '2026-01-01T00:00:00+00:00',
            'updated_at' => '2026-01-02T00:00:00+00:00',
        ],
        'links' => [
            'self' => ['href' => '/orders/1'],
        ],
    ]);
});

it('omits timestamps when disabled', function () {
    $model = new PlainModel(id: 1, status: 'paid');

    $array = (new OrderResourceWithoutTimestamps($model))->toArray(request());

    expect($array['attributes'])->toBe(['status' => 'paid'])
        ->and($array['links']['self']['href'])->toBeNull();
});

it('prefers safeHashID over the primary key', function () {
    $model = new SafeHashIdModel(id: 1, status: 'paid', hash: 'hashed-1');

    $array = (new OrderResource($model))->toArray(request());

    expect($array['id'])->toBe('hashed-1');
});

it('falls back to the Hashing interface when safeHashID is absent', function () {
    $model = new HashingModel(id: 1, status: 'paid', hash: 'hashed-2');

    $array = (new OrderResource($model))->toArray(request());

    expect($array['id'])->toBe('hashed-2');
});

it('falls back to getKey() when nothing hashid-related is available', function () {
    $model = new PlainModel(id: 7, status: 'paid');

    $array = (new OrderResource($model))->toArray(request());

    expect($array['id'])->toBe(7);
});

it('falls back to the id property when there is no getKey() either', function () {
    $model = new KeylessModel(id: 9, status: 'paid');

    $array = (new OrderResource($model))->toArray(request());

    expect($array['id'])->toBe(9);
});
