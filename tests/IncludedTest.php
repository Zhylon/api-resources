<?php

declare(strict_types=1);

use Zhylon\ApiResources\Tests\Fixtures\TagResource;
use Zhylon\ApiResources\Http\Resources\Relationship;
use Zhylon\ApiResources\Tests\Fixtures\PostResource;
use Zhylon\ApiResources\Tests\Fixtures\UserResource;
use Zhylon\ApiResources\Tests\Fixtures\Eloquent\User;

it('takes id and type from the related resource and adds it to included', function () {
    PostResource::using(fn () => [
        'author' => $this->relationship('author')->resource(UserResource::class),
    ]);

    $response = respond(new PostResource(post(['author' => user()])));

    expect($response['data']['relationships']['author'])->toBe(['data' => ['id' => 'user-5', 'type' => 'user']])
        ->and($response['included'])->toBe([
            ['id' => 'user-5', 'type' => 'user', 'attributes' => ['name' => 'Ada']],
        ]);
});

it('has no included key when nothing is loaded', function () {
    PostResource::using(fn () => [
        'author' => $this->relationship('author')->resource(UserResource::class),
    ]);

    expect(respond(new PostResource(post())))->toHaveKeys(['data'])->not->toHaveKey('included');
});

it('de-duplicates included resources across a collection', function () {
    PostResource::using(fn () => [
        'author' => $this->relationship('author')->resource(UserResource::class),
    ]);

    $author = user();
    $response = respond(PostResource::collection(collect([
        post(['author' => $author], 1),
        post(['author' => $author], 2),
        post(['author' => user(6)], 3),
    ])));

    expect(array_column($response['included'], 'id'))->toBe(['user-5', 'user-6'])
        ->and($response['data'][0]['relationships']['author']['data']['id'])->toBe('user-5');
});

it('includes nested relations that are loaded', function () {
    PostResource::using(fn () => [
        'author' => $this->relationship('author')->resource(UserResource::class),
    ]);
    UserResource::using(fn () => [
        'tags' => $this->relationship('tags')->resource(TagResource::class),
    ]);

    $response = respond(new PostResource(post(['author' => user(5, ['tags' => collect([tag(1), tag(2)])])])));

    expect(array_map(fn ($doc) => $doc['type'].':'.$doc['id'], $response['included']))
        ->toBe(['user:user-5', 'tag:1', 'tag:2'])
        ->and($response['included'][0]['relationships']['tags']['data'])->toBe([
            ['id' => 1, 'type' => 'tag'],
            ['id' => 2, 'type' => 'tag'],
        ]);
});

it('never includes resources that are part of the primary data', function () {
    PostResource::using(fn () => [
        'related' => $this->relationship('related')->resource(PostResource::class),
    ]);

    $response = respond(PostResource::collection(collect([
        post(['related' => collect([post([], 2)])], 1),
        post([], 2),
    ])));

    expect($response)->not->toHaveKey('included');
});

it('keeps identifiers but skips included with withoutIncluded() or a condition', function () {
    PostResource::using(fn () => [
        'author' => $this->relationship('author')->resource(UserResource::class)->withoutIncluded(),
        'tags'   => $this->relationship('tags')->resource(TagResource::class)->included(fn () => false),
    ]);

    $response = respond(new PostResource(post(['author' => user(), 'tags' => collect([tag(1)])])));

    expect($response)->not->toHaveKey('included')
        ->and($response['data']['relationships']['author']['data']['type'])->toBe('user')
        ->and($response['data']['relationships']['tags']['data'][0]['type'])->toBe('tag');
});

it('detects the resource class from JsonResource data', function () {
    PostResource::using(fn () => [
        'author' => Relationship::make(UserResource::make($this->whenLoaded('author'))),
        'tags'   => Relationship::make(TagResource::collection($this->whenLoaded('tags'))),
    ]);

    $response = respond(new PostResource(post(['author' => user(), 'tags' => collect([tag(1)])])));

    expect(array_column($response['included'], 'type'))->toBe(['user', 'tag']);
});

it('picks a resource per item via a closure', function () {
    PostResource::using(fn () => [
        'mentions' => Relationship::toMany(null, [user(), tag(3)])
            ->resource(fn ($item) => $item instanceof User
                ? new UserResource($item)
                : new TagResource($item)),
    ]);

    $response = respond(new PostResource(post()));

    expect($response['data']['relationships']['mentions']['data'])->toBe([
        ['id' => 'user-5', 'type' => 'user'],
        ['id' => 3, 'type' => 'tag'],
    ])->and(array_column($response['included'], 'type'))->toBe(['user', 'tag']);
});

it('does not render bare ids through the resource', function () {
    PostResource::using(fn () => [
        'author' => $this->relationship('author', 'user', $this->user_id)->resource(UserResource::class),
    ]);

    $response = respond(new PostResource(post()));

    expect($response['data']['relationships']['author']['data'])->toBe(['id' => 5, 'type' => 'user'])
        ->and($response)->not->toHaveKey('included');
});

it('throws a helpful exception for cyclic relations', function () {
    PostResource::using(fn () => [
        'author' => $this->relationship('author')->resource(UserResource::class),
    ]);
    UserResource::using(fn () => [
        'posts' => $this->relationship('posts')->resource(PostResource::class),
    ]);

    $author = user();
    $post = post(['author' => $author]);
    $author->setRelation('posts', collect([$post]));

    respond(new PostResource($post));
})->throws(LogicException::class, 'Maximum relationship depth');
