<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Http\Resources\Json\JsonResource;
use Zhylon\ApiResources\Http\Resources\ResourceId;
use Zhylon\ApiResources\Tests\Fixtures\PlainModel;
use Zhylon\ApiResources\Tests\Fixtures\HashingModel;
use Zhylon\ApiResources\Tests\Fixtures\KeylessModel;
use Zhylon\ApiResources\Tests\Fixtures\PostResource;
use Zhylon\ApiResources\Tests\Fixtures\OrderResource;
use Zhylon\ApiResources\Tests\Fixtures\SafeHashIdModel;
use Zhylon\ApiResources\Http\Resources\BuildsApiResource;
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
        ->and($array)->not->toHaveKey('links');
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

it('renders custom timestamp fields, null values and plain strings', function () {
    $resource = new class(new PlainModel(id: 1, status: 'paid', created_at: Carbon::parse('2026-01-01T00:00:00Z'))) extends JsonResource
    {
        use BuildsApiResource;

        public function toArray($request): array
        {
            $this->resource->deployed_at = new DateTimeImmutable('2026-02-03T04:05:06+02:00');
            $this->resource->legacy_at = '2020-01-01';

            return $this->toApiResource(
                type: 'order',
                attributes: ['status' => $this->status],
                timestamps: ['created_at', 'updated_at', 'deployed_at', 'legacy_at'],
            );
        }
    };

    expect($resource->toArray(request())['attributes'])->toBe([
        'status'      => 'paid',
        'created_at'  => '2026-01-01T00:00:00+00:00',
        'updated_at'  => null,
        'deployed_at' => '2026-02-03T04:05:06+02:00',
        'legacy_at'   => '2020-01-01',
    ]);
});

it('allows overriding the timestamp format', function () {
    $resource = new class(new PlainModel(id: 1, status: 'paid', created_at: Carbon::parse('2026-01-01T12:00:00Z'))) extends JsonResource
    {
        use BuildsApiResource;

        public function toArray($request): array
        {
            return $this->toApiResource(type: 'order', attributes: [], timestamps: ['created_at']);
        }

        protected function formatTimestamp(mixed $value): mixed
        {
            return $value?->getTimestamp();
        }
    };

    expect($resource->toArray(request())['attributes'])->toBe(['created_at' => 1767268800]);
});

it('casts ids to strings when enabled, for resources and relationship identifiers', function () {
    ResourceId::castToString();

    PostResource::using(fn () => [
        'author' => $this->relationship('author', 'user', 5),
        'tags'   => $this->relationship('tags', 'tag', [1, 'abc']),
    ]);

    $array = render(post());

    expect($array['id'])->toBe('1')
        ->and($array['relationships']['author']['data']['id'])->toBe('5')
        ->and($array['relationships']['tags']['data'])->toBe([
            ['id' => '1', 'type' => 'tag'],
            ['id' => 'abc', 'type' => 'tag'],
        ]);
});

it('keeps integer ids by default', function () {
    expect(render(post())['id'])->toBe(1);
});

it('can keep the 1.x empty self link explicitly', function () {
    $resource = new class(new PlainModel(id: 1, status: 'paid')) extends JsonResource
    {
        use BuildsApiResource;

        public function toArray($request): array
        {
            return $this->toApiResource(type: 'order', attributes: [], links: ['self' => ['href' => null]]);
        }
    };

    expect($resource->toArray(request())['links'])->toBe(['self' => ['href' => null]]);
});
