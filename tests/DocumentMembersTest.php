<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Http\Resources\Json\JsonResource;
use Zhylon\ApiResources\Tests\Fixtures\TagResource;
use Zhylon\ApiResources\Http\Resources\SparseFieldsets;
use Zhylon\ApiResources\Http\Resources\BuildsApiResource;

class DocumentMembersResource extends JsonResource
{
    use BuildsApiResource;

    public static int $expensiveCalls = 0;

    public function toArray(Request $request): array
    {
        return $this->toApiResource(
            type: 'site',
            attributes: [
                'name'      => $this->name,
                'expensive' => function () {
                    static::$expensiveCalls++;

                    return 'computed';
                },
            ],
            selfHref: '/sites/1',
            timestamps: true,
            links: [
                'dashboard' => '/dashboard/sites/1',
                'logs'      => fn () => '/sites/1/logs',
                'hidden'    => null,
                'missing'   => $this->when(false, '/never'),
            ],
            meta: [
                'can' => ['update' => true, 'delete' => false],
            ],
        );
    }
}

beforeEach(fn () => DocumentMembersResource::$expensiveCalls = 0);

it('renders resource-level links and meta', function () {
    $array = (new DocumentMembersResource(tag(1)))->resolve(request());

    expect(array_keys($array))->toBe(['id', 'type', 'attributes', 'links', 'meta'])
        ->and($array['links'])->toBe([
            'self'      => ['href' => '/sites/1'],
            'dashboard' => ['href' => '/dashboard/sites/1'],
            'logs'      => ['href' => '/sites/1/logs'],
        ])
        ->and($array['meta'])->toBe(['can' => ['update' => true, 'delete' => false]]);
});

it('evaluates closure attributes', function () {
    $array = (new DocumentMembersResource(tag(1)))->resolve(request());

    expect($array['attributes']['expensive'])->toBe('computed')
        ->and(DocumentMembersResource::$expensiveCalls)->toBe(1);
});

it('does not evaluate closure attributes excluded by a sparse fieldset', function () {
    SparseFieldsets::enable();
    app()->instance('request', Request::create('/sites', 'GET', ['fields' => ['site' => 'name,created_at']]));

    $array = (new DocumentMembersResource(tag(1)))->resolve(request());

    expect(array_keys($array['attributes']))->toBe(['name', 'created_at'])
        ->and(DocumentMembersResource::$expensiveCalls)->toBe(0);
});

it('adds top-level meta and links to a single resource', function () {
    $response = respond(
        TagResource::make(tag(1))
            ->withMeta(['api_version' => '2'])
            ->withMeta(fn () => ['generated' => true])
            ->withLinks(['docs' => '/docs']),
    );

    expect($response['meta'])->toBe(['api_version' => '2', 'generated' => true])
        ->and($response['links'])->toBe(['docs' => ['href' => '/docs']]);
});

it('merges top-level meta and links with pagination info', function () {
    $paginator = new LengthAwarePaginator([tag(1), tag(2)], total: 4, perPage: 2, currentPage: 1, options: ['path' => '/tags']);

    $response = respond(
        TagResource::collection($paginator)
            ->withMeta(['filters' => ['label' => 'php']])
            ->withLinks(['docs' => '/docs']),
    );

    expect($response['meta'])->toMatchArray(['total' => 4, 'filters' => ['label' => 'php']])
        ->and($response['links'])->toHaveKeys(['first', 'next', 'docs'])
        ->and($response['links']['docs'])->toBe(['href' => '/docs'])
        ->and(array_column($response['data'], 'id'))->toBe([1, 2]);
});

it('keeps Laravel additional() working', function () {
    $response = respond(TagResource::make(tag(1))->additional(['meta' => ['a' => 1]])->withMeta(['b' => 2]));

    expect($response['meta'])->toBe(['b' => 2, 'a' => 1]);
});
