<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Zhylon\ApiResources\Http\Errors\ApiError;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Resources\Json\JsonResource;
use Zhylon\ApiResources\Http\Resources\ResourceId;
use Zhylon\ApiResources\Http\Resources\Relationship;
use Zhylon\ApiResources\Tests\Fixtures\PostResource;
use Zhylon\ApiResources\Http\Errors\ApiErrorResponse;
use Zhylon\ApiResources\Http\Resources\SparseFieldsets;
use Zhylon\ApiResources\Http\Errors\ApiExceptionRenderer;

it('supports every ApiError member and serializes to JSON', function () {
    $error = ApiError::make(400)
        ->status(409)
        ->id('err-1')
        ->title('Locked')
        ->header('If-Match')
        ->link('about', '/docs', ['lang' => 'en']);

    expect(json_decode(json_encode($error), true))->toBe([
        'id'     => 'err-1',
        'status' => '409',
        'title'  => 'Locked',
        'source' => ['header' => 'If-Match'],
        'links'  => ['about' => ['href' => '/docs', 'meta' => ['lang' => 'en']]],
    ]);
});

it('exposes and extends the errors of a response', function () {
    $response = ApiErrorResponse::make(ApiError::make(404))->add(ApiError::make(404, 'second'));

    expect($response->errors())->toHaveCount(2)
        ->and($response->resolveStatus())->toBe(404)
        ->and((new ApiErrorResponse)->resolveStatus())->toBe(500);
});

it('hides the message of 5xx http exceptions unless debugging', function () {
    app(ExceptionHandler::class)->renderable(new ApiExceptionRenderer);
    Route::get('api/down', fn () => abort(503, 'Database migration running'));

    config(['app.debug' => false]);
    $this->getJson('api/down')->assertStatus(503)->assertExactJson(['errors' => [
        ['status' => '503', 'title' => 'Service Unavailable'],
    ]]);

    config(['app.debug' => true]);
    $error = $this->getJson('api/down')->json('errors.0');

    expect($error['detail'])->toBe('Database migration running')
        ->and($error['meta'])->toHaveKeys(['exception', 'file', 'line', 'trace']);
});

it('lets debug() override app.debug', function () {
    config(['app.debug' => true]);
    app(ExceptionHandler::class)->renderable((new ApiExceptionRenderer)->debug(false));
    Route::get('api/boom', fn () => throw new RuntimeException('secret'));

    expect($this->getJson('api/boom')->json('errors.0'))->not->toHaveKeys(['detail', 'meta']);
});

it('replaces data and type of a relationship', function () {
    PostResource::using(fn () => [
        'author' => Relationship::toOne('placeholder')->data(7)->type('user'),
    ]);

    expect(render(post())['relationships']['author']['data'])->toBe(['id' => 7, 'type' => 'user']);
});

it('merges array and closure meta in any order', function () {
    $relationship = Relationship::linksOnly(['related' => '/x'])
        ->meta(['a' => 1])
        ->meta(fn () => ['b' => 2])
        ->meta(['c' => 3]);

    expect($relationship->resolve())->toBe([
        'links' => ['related' => ['href' => '/x']],
        'meta'  => ['a' => 1, 'b' => 2, 'c' => 3],
    ]);
});

it('renders and counts generator data', function () {
    $relationship = Relationship::toMany('tag', fn () => (function () {
        yield tag(1);
        yield tag(2);
    })())->withCount();

    expect($relationship->resolve())->toBe([
        'data' => [['id' => 1, 'type' => 'tag'], ['id' => 2, 'type' => 'tag']],
        'meta' => ['count' => 2],
    ]);
});

it('counts a single forced to-many item as one', function () {
    expect(Relationship::make(tag(1), 'tag')->many()->withCount()->resolve()['meta'])->toBe(['count' => 1]);
});

it('returns null for a nested relation behind a loaded empty relation', function () {
    PostResource::using(fn () => [
        'avatar' => $this->relationship('author.avatar', 'image'),
    ]);

    expect(render(post(['author' => null]))['relationships']['avatar'])->toBe(['data' => null]);
});

it('includes related documents without id or type as they are', function () {
    $plain = new class(null) extends JsonResource
    {
        public function toArray(Request $request): array
        {
            return ['label' => $this->label];
        }
    };

    PostResource::using(fn () => [
        'tags' => Relationship::toMany('tag', [tag(1, 'a'), tag(2, 'b')])
            ->resource(fn ($tag) => new $plain($tag)),
    ]);

    $response = respond(new PostResource(post()));

    expect($response['included'])->toBe([['label' => 'a'], ['label' => 'b']]);
});

it('resolves ids from arrays and returns null for other values', function () {
    expect(ResourceId::resolve(['id' => 'x']))->toBe('x')
        ->and(ResourceId::resolve(['name' => 'x']))->toBeNull()
        ->and(ResourceId::resolve(1.5))->toBeNull()
        ->and(ResourceId::resolve(null))->toBeNull();
});

it('can disable sparse fieldsets again', function () {
    SparseFieldsets::enable();
    SparseFieldsets::disable();

    expect(SparseFieldsets::enabled())->toBeFalse();
});
