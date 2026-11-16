<?php

declare(strict_types=1);

use Illuminate\Http\Resources\MissingValue;
use Zhylon\ApiResources\Http\Resources\ResourceId;
use Zhylon\ApiResources\Http\Resources\Relationship;
use Zhylon\ApiResources\Tests\Fixtures\Eloquent\Tag;
use Zhylon\ApiResources\Tests\Fixtures\PostResource;
use Zhylon\ApiResources\Tests\Fixtures\UserResource;
use Zhylon\ApiResources\Tests\Fixtures\Eloquent\Post;
use Zhylon\ApiResources\Tests\Fixtures\Eloquent\User;

function post(array $relations = []): Post
{
    $post = new Post(['id' => 1, 'title' => 'Hello', 'user_id' => 5]);

    foreach ($relations as $name => $value) {
        $post->setRelation($name, $value);
    }

    return $post;
}

function render(Post $post): array
{
    return (new PostResource($post))->resolve(request());
}

afterEach(function () {
    PostResource::$relationships = null;
    ResourceId::resolveUsing(null);
});

it('omits the relationships key entirely when there are none', function () {
    expect(render(post()))->not->toHaveKey('relationships')
        ->and(array_keys(render(post())))->toBe(['id', 'type', 'attributes', 'links']);
});

it('renders a loaded to-one relation between attributes and links', function () {
    PostResource::using(fn () => [
        'author' => $this->relationship('author', 'user'),
    ]);

    $array = render(post(['author' => new User(['id' => 5])]));

    expect(array_keys($array))->toBe(['id', 'type', 'attributes', 'relationships', 'links'])
        ->and($array['relationships'])->toBe([
            'author' => ['data' => ['id' => 'user-5', 'type' => 'user']],
        ]);
});

it('omits a relation that is not loaded', function () {
    PostResource::using(fn () => [
        'author' => $this->relationship('author', 'user'),
    ]);

    expect(render(post()))->not->toHaveKey('relationships');
});

it('renders links and meta without data when the relation is not loaded', function () {
    PostResource::using(fn () => [
        'author' => $this->relationship('author', 'user')
            ->related('/posts/1/author')
            ->meta(['note' => 'x']),
    ]);

    expect(render(post())['relationships']['author'])->toBe([
        'links' => ['related' => ['href' => '/posts/1/author']],
        'meta'  => ['note' => 'x'],
    ]);
});

it('can omit the relation when missing even with links configured', function () {
    PostResource::using(fn () => [
        'author' => $this->relationship('author', 'user')->related('/posts/1/author')->omitWhenMissing(),
    ]);

    expect(render(post()))->not->toHaveKey('relationships');
});

it('renders data null for a loaded but empty to-one relation', function () {
    PostResource::using(fn () => [
        'author' => $this->relationship('author', 'user'),
    ]);

    expect(render(post(['author' => null]))['relationships']['author'])->toBe(['data' => null]);
});

it('renders a loaded to-many relation as a list of identifiers', function () {
    PostResource::using(fn () => [
        'tags' => $this->relationship('tags', 'tag'),
    ]);

    $array = render(post(['tags' => collect([new Tag(['id' => 1]), new Tag(['id' => 2])])]));

    expect($array['relationships']['tags']['data'])->toBe([
        ['id' => 1, 'type' => 'tag'],
        ['id' => 2, 'type' => 'tag'],
    ]);
});

it('renders an empty array for an empty to-many relation', function () {
    PostResource::using(fn () => [
        'tags' => $this->relationship('tags', 'tag'),
    ]);

    expect(render(post(['tags' => collect()]))['relationships']['tags'])->toBe(['data' => []]);
});

it('renders an identifier from a foreign key without loading the relation', function () {
    PostResource::using(fn () => [
        'author' => $this->relationship('author', 'user', $this->user_id),
    ]);

    expect(render(post())['relationships']['author']['data'])->toBe(['id' => 5, 'type' => 'user']);
});

it('supports per-relationship id resolution', function () {
    PostResource::using(fn () => [
        'author' => Relationship::toOne('user', $this->user_id)->identifyUsing(fn ($key) => 'u_'.$key),
    ]);

    expect(render(post())['relationships']['author']['data']['id'])->toBe('u_5');
});

it('uses an app-wide id resolver and falls back to the default chain on null', function () {
    ResourceId::resolveUsing(fn ($resource) => $resource instanceof Tag ? 'tag-'.$resource->getKey() : null);

    PostResource::using(fn () => [
        'tags'   => $this->relationship('tags', 'tag'),
        'author' => $this->relationship('author', 'user'),
    ]);

    $array = render(post(['tags' => collect([new Tag(['id' => 3])]), 'author' => new User(['id' => 5])]));

    expect($array['relationships']['tags']['data'][0]['id'])->toBe('tag-3')
        ->and($array['relationships']['author']['data']['id'])->toBe('user-5')
        ->and($array['id'])->toBe(1);
});

it('resolves the type per item for polymorphic relations', function () {
    PostResource::using(fn () => [
        'mentions' => Relationship::toMany(
            fn ($model) => $model instanceof User ? 'user' : 'tag',
            [new User(['id' => 1]), new Tag(['id' => 2])],
        ),
    ]);

    expect(render(post())['relationships']['mentions']['data'])->toBe([
        ['id' => 'user-1', 'type' => 'user'],
        ['id' => 2, 'type' => 'tag'],
    ]);
});

it('adds identifier meta per related item', function () {
    PostResource::using(fn () => [
        'tags' => $this->relationship('tags', 'tag')
            ->identifierMeta(fn ($tag) => ['position' => $tag->position]),
    ]);

    $array = render(post(['tags' => collect([new Tag(['id' => 1, 'position' => 2])])]));

    expect($array['relationships']['tags']['data'][0])->toBe([
        'id'   => 1,
        'type' => 'tag',
        'meta' => ['position' => 2],
    ]);
});

it('counts loaded to-many data or uses an explicit count', function () {
    PostResource::using(fn () => [
        'tags'     => $this->relationship('tags', 'tag')->withCount(),
        'comments' => Relationship::linksOnly(['related' => '/posts/1/comments'])->withCount(42, 'total'),
    ]);

    $array = render(post(['tags' => collect([new Tag(['id' => 1]), new Tag(['id' => 2])])]));

    expect($array['relationships']['tags']['meta'])->toBe(['count' => 2])
        ->and($array['relationships']['comments'])->toBe([
            'links' => ['related' => ['href' => '/posts/1/comments']],
            'meta'  => ['total' => 42],
        ]);
});

it('renders zero as count for a loaded empty to-many relation', function () {
    PostResource::using(fn () => [
        'tags' => $this->relationship('tags', 'tag')->withCount(),
    ]);

    expect(render(post(['tags' => collect()]))['relationships']['tags'])->toBe([
        'data' => [],
        'meta' => ['count' => 0],
    ]);
});

it('can render links only, even when the relation is loaded', function () {
    PostResource::using(fn () => [
        'tags' => $this->relationship('tags', 'tag')->withoutData()->self('/posts/1/relationships/tags'),
    ]);

    expect(render(post(['tags' => collect([new Tag(['id' => 1])])]))['relationships']['tags'])->toBe([
        'links' => ['self' => ['href' => '/posts/1/relationships/tags']],
    ]);
});

it('supports links with meta, closures, raw arrays and drops null links', function () {
    PostResource::using(fn () => [
        'author' => $this->relationship('author', 'user')
            ->related(fn () => '/users/5', ['title' => 'Author'])
            ->self(null)
            ->link('docs', ['href' => '/docs', 'rel' => 'help']),
    ]);

    expect(render(post(['author' => new User(['id' => 5])]))['relationships']['author']['links'])->toBe([
        'related' => ['href' => '/users/5', 'meta' => ['title' => 'Author']],
        'docs'    => ['href' => '/docs', 'rel' => 'help'],
    ]);
});

it('evaluates conditions', function () {
    PostResource::using(fn () => [
        'author' => $this->relationship('author', 'user')->when(false),
        'tags'   => $this->relationship('tags', 'tag')->unless(fn () => false),
    ]);

    $array = render(post(['author' => new User(['id' => 5]), 'tags' => collect()]));

    expect($array['relationships'])->toBe(['tags' => ['data' => []]]);
});

it('evaluates lazy data only when rendered', function () {
    $calls = 0;

    PostResource::using(fn () => [
        'author' => Relationship::toOne('user', function () use (&$calls) {
            $calls++;

            return new User(['id' => 5]);
        })->when(false),
    ]);

    render(post());

    expect($calls)->toBe(0);
});

it('unwraps json resources and resource collections, including missing ones', function () {
    PostResource::using(fn () => [
        'author' => Relationship::make(UserResource::make($this->whenLoaded('author')), 'user'),
        'tags'   => Relationship::make(UserResource::collection($this->whenLoaded('tags')), 'tag'),
    ]);

    expect(render(post()))->not->toHaveKey('relationships');

    $array = render(post(['author' => new User(['id' => 5]), 'tags' => collect([new Tag(['id' => 1])])]));

    expect($array['relationships'])->toBe([
        'author' => ['data' => ['id' => 'user-5', 'type' => 'user']],
        'tags'   => ['data' => [['id' => 1, 'type' => 'tag']]],
    ]);
});

it('forces cardinality with one() and many()', function () {
    PostResource::using(fn () => [
        'author' => Relationship::make([new User(['id' => 5])], 'user')->one(),
        'tags'   => Relationship::make(null, 'tag')->many(),
    ]);

    expect(render(post())['relationships'])->toBe([
        'author' => ['data' => ['id' => 'user-5', 'type' => 'user']],
        'tags'   => ['data' => []],
    ]);
});

it('walks nested loaded relations with dot notation', function () {
    $author = new User(['id' => 5]);
    $author->setRelation('avatar', new Tag(['id' => 9]));

    PostResource::using(fn () => [
        'avatar'  => $this->relationship('author.avatar', 'image'),
        'missing' => $this->relationship('author.company', 'company'),
    ]);

    expect(render(post(['author' => $author]))['relationships'])->toBe([
        'avatar' => ['data' => ['id' => 9, 'type' => 'image']],
    ]);
});

it('passes raw arrays through and skips null, missing values and closures returning them', function () {
    PostResource::using(fn () => [
        'raw'     => ['data' => ['id' => 'x', 'type' => 'thing']],
        'null'    => null,
        'missing' => new MissingValue,
        'lazy'    => fn () => Relationship::toOne('user', 7),
    ]);

    expect(render(post())['relationships'])->toBe([
        'raw'  => ['data' => ['id' => 'x', 'type' => 'thing']],
        'lazy' => ['data' => ['id' => 7, 'type' => 'user']],
    ]);
});

it('throws a helpful exception when no type is given', function () {
    PostResource::using(fn () => [
        'author' => $this->relationship('author'),
    ]);

    render(post(['author' => new User(['id' => 5])]));
})->throws(LogicException::class, 'Unable to determine the relationship type');

it('exposes the related items for building included documents in the app', function () {
    $relationship = Relationship::toMany('tag', collect([new Tag(['id' => 1]), new Tag(['id' => 2])]));

    expect($relationship->relatedItems()->pluck('id')->all())->toBe([1, 2])
        ->and(Relationship::toOne('user')->relatedItems()->all())->toBe([]);
});

it('renders relationships for every item of a collection', function () {
    PostResource::using(fn () => [
        'author' => $this->relationship('author', 'user'),
    ]);

    $array = PostResource::collection(collect([post(['author' => new User(['id' => 5])]), post()]))
        ->response()
        ->getData(true);

    expect($array['data'][0]['relationships']['author']['data']['id'])->toBe('user-5')
        ->and($array['data'][1])->not->toHaveKey('relationships');
});

it('allows a custom Relationship subclass via newRelationship()', function () {
    $resource = new class(post(['author' => new User(['id' => 5])])) extends PostResource
    {
        protected function newRelationship(mixed $data, string|Closure|null $type): Relationship
        {
            return parent::newRelationship($data, $type)->meta(['app' => true]);
        }
    };

    PostResource::using(fn () => [
        'author' => $this->relationship('author', 'user'),
    ]);

    expect($resource->resolve(request())['relationships']['author'])->toBe([
        'data' => ['id' => 'user-5', 'type' => 'user'],
        'meta' => ['app' => true],
    ]);
});
