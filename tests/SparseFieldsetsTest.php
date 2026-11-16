<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Zhylon\ApiResources\Tests\Fixtures\PostResource;
use Zhylon\ApiResources\Tests\Fixtures\UserResource;
use Zhylon\ApiResources\Http\Resources\SparseFieldsets;

function withQuery(array $query): void
{
    app()->instance('request', Request::create('/posts', 'GET', $query));
}

beforeEach(function () {
    PostResource::using(fn () => [
        'author' => $this->relationship('author')->resource(UserResource::class),
        'tags'   => $this->relationship('tags', 'tag'),
    ]);
});

it('ignores the fields parameter while disabled', function () {
    withQuery(['fields' => ['post' => 'nothing']]);

    expect(render(post())['attributes'])->toBe(['title' => 'Hello']);
});

it('limits attributes and relationships per type', function () {
    SparseFieldsets::enable();
    withQuery(['fields' => ['post' => 'author', 'user' => '']]);

    $response = respond(new PostResource(post(['author' => user(), 'tags' => collect([tag(1)])])));

    expect($response['data']['attributes'])->toBe([])
        ->and(array_keys($response['data']['relationships']))->toBe(['author'])
        ->and($response['included'][0]['attributes'])->toBe([])
        ->and($response['included'][0])->toHaveKeys(['id', 'type']);
});

it('leaves types without a fieldset unrestricted', function () {
    SparseFieldsets::enable();
    withQuery(['fields' => ['user' => 'name']]);

    expect(render(post())['attributes'])->toBe(['title' => 'Hello']);
});

it('supports a custom parameter name and array values', function () {
    SparseFieldsets::enable('only');
    withQuery(['only' => ['post' => ['title']], 'fields' => ['post' => 'nothing']]);

    expect(render(post())['attributes'])->toBe(['title' => 'Hello']);
});

it('supports a fully custom resolver', function () {
    SparseFieldsets::resolveUsing(fn (Request $request) => ['post' => []]);

    expect(SparseFieldsets::enabled())->toBeTrue()
        ->and(render(post())['attributes'])->toBe([]);
});

it('ignores a non-array fields parameter', function () {
    SparseFieldsets::enable();
    withQuery(['fields' => 'title']);

    expect(render(post())['attributes'])->toBe(['title' => 'Hello']);
});
