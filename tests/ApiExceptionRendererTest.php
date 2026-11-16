<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Auth\AuthenticationException;
use Zhylon\ApiResources\Http\Errors\ApiError;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Auth\Access\AuthorizationException;
use Zhylon\ApiResources\Http\Errors\ApiErrorResponse;
use Zhylon\ApiResources\Tests\Fixtures\Eloquent\Post;
use Zhylon\ApiResources\Http\Errors\ProvidesApiErrors;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Zhylon\ApiResources\Http\Errors\ApiExceptionRenderer;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

class SiteLockedException extends RuntimeException implements ProvidesApiErrors
{
    public function toApiErrors(Request $request): ApiError
    {
        return ApiError::make(409, 'The site is locked.')->code('site_locked');
    }
}

function throwing(Closure $throw, ?ApiExceptionRenderer $renderer = null): void
{
    app(ExceptionHandler::class)->renderable($renderer ?? new ApiExceptionRenderer);

    Route::any('api/test', $throw);
    Route::any('web/test', $throw);
}

beforeEach(fn () => config(['app.debug' => false]));

it('renders validation errors with pointers and rule codes', function () {
    throwing(fn () => Validator::make(
        ['address' => ['city' => 'x']],
        ['name' => 'required', 'address.city' => 'min:3|alpha:ascii', 'a/b' => 'required'],
    )->validate());

    $response = $this->postJson('api/test');

    $response->assertStatus(422)->assertExactJson(['errors' => [
        ['status' => '422', 'code' => 'required', 'title' => 'Unprocessable Content', 'detail' => 'The name field is required.', 'source' => ['pointer' => '/name']],
        ['status' => '422', 'code' => 'min', 'title' => 'Unprocessable Content', 'detail' => 'The address.city field must be at least 3 characters.', 'source' => ['pointer' => '/address/city']],
        ['status' => '422', 'code' => 'required', 'title' => 'Unprocessable Content', 'detail' => 'The a/b field is required.', 'source' => ['pointer' => '/a~1b']],
    ]]);
});

it('supports a pointer prefix, custom pointers and disabling rule codes', function () {
    throwing(
        fn () => Validator::make([], ['name' => 'required'])->validate(),
        (new ApiExceptionRenderer)->pointerPrefix('/data/attributes/')->withRuleCodes(false),
    );

    expect($this->postJson('api/test')->json('errors.0'))
        ->toHaveKey('source', ['pointer' => '/data/attributes/name'])
        ->not->toHaveKey('code');
});

it('omits the source when a custom pointer resolver returns null', function () {
    throwing(
        fn () => Validator::make([], ['name' => 'required'])->validate(),
        (new ApiExceptionRenderer)->pointerUsing(fn () => null),
    );

    expect($this->postJson('api/test')->json('errors.0'))->not->toHaveKey('source');
});

it('renders authentication and authorization errors', function () {
    throwing(fn () => throw new AuthenticationException);
    $this->getJson('api/test')->assertStatus(401)->assertJsonPath('errors.0.detail', 'Unauthenticated.');

    throwing(fn () => throw new AuthorizationException('Nope.'));
    $this->getJson('api/test')->assertStatus(403)->assertJsonPath('errors.0.title', 'Forbidden')
        ->assertJsonPath('errors.0.detail', 'Nope.');
});

it('hides the model class of not-found errors outside debug mode', function () {
    throwing(fn () => throw (new ModelNotFoundException)->setModel(Post::class, [1]));

    $this->getJson('api/test')->assertStatus(404)->assertExactJson(['errors' => [
        ['status' => '404', 'title' => 'Not Found', 'detail' => 'The requested resource was not found.'],
    ]]);
});

it('keeps the headers of http exceptions', function () {
    throwing(fn () => throw new TooManyRequestsHttpException(30));

    $this->getJson('api/test')->assertStatus(429)->assertHeader('Retry-After', '30')
        ->assertJsonPath('errors.0.title', 'Too Many Requests');
});

it('hides details of server errors unless debugging', function () {
    throwing(fn () => throw new RuntimeException('secret'));

    $this->getJson('api/test')->assertStatus(500)->assertExactJson(['errors' => [
        ['status' => '500', 'title' => 'Internal Server Error'],
    ]]);

    config(['app.debug' => true]);

    $error = $this->getJson('api/test')->json('errors.0');

    expect($error['detail'])->toBe('secret')
        ->and($error['meta']['exception'])->toBe(RuntimeException::class)
        ->and($error['meta'])->toHaveKeys(['file', 'line', 'trace']);
});

it('lets exceptions render themselves via ProvidesApiErrors', function () {
    throwing(fn () => throw new SiteLockedException);

    $this->getJson('api/test')->assertStatus(409)->assertExactJson(['errors' => [
        ['status' => '409', 'code' => 'site_locked', 'title' => 'Conflict', 'detail' => 'The site is locked.'],
    ]]);
});

it('supports custom mappings with fallback on null', function () {
    throwing(
        fn () => throw new DomainException('boom'),
        (new ApiExceptionRenderer)
            ->map(DomainException::class, fn (DomainException $e) => [
                ApiError::make(400, $e->getMessage())->code('domain')->parameter('filter[x]'),
                ApiError::make(400, 'second')->link('about', '/docs/errors/domain'),
            ])
            ->map(LogicException::class, fn () => null),
    );

    $this->getJson('api/test')->assertStatus(400)->assertExactJson(['errors' => [
        ['status' => '400', 'code' => 'domain', 'title' => 'Bad Request', 'detail' => 'boom', 'source' => ['parameter' => 'filter[x]']],
        ['status' => '400', 'title' => 'Bad Request', 'detail' => 'second', 'links' => ['about' => ['href' => '/docs/errors/domain']]],
    ]]);
});

it('only renders for api requests by default and can be customized with when()', function () {
    throwing(fn () => throw new RuntimeException('x'));

    expect($this->get('web/test')->headers->get('content-type'))->not->toContain('json')
        ->and($this->get('api/test')->json('errors.0.status'))->toBe('500');
});

it('respects a custom when() condition', function () {
    throwing(fn () => throw new RuntimeException('x'), (new ApiExceptionRenderer)->when(fn (Request $request) => $request->is('web/*')));

    expect($this->getJson('api/test')->json())->not->toHaveKey('errors')
        ->and($this->get('web/test')->json('errors.0.status'))->toBe('500');
});

it('derives the response status from mixed errors', function () {
    expect(ApiErrorResponse::make(ApiError::make(404), ApiError::make(422))->resolveStatus())->toBe(400)
        ->and(ApiErrorResponse::make(ApiError::make(404), ApiError::make(500))->resolveStatus())->toBe(500)
        ->and(ApiErrorResponse::make(ApiError::make(404))->status(410)->resolveStatus())->toBe(410)
        ->and(ApiErrorResponse::make(ApiError::make(409))->meta(['request_id' => 'r1'])->toArray())
        ->toBe(['errors' => [['status' => '409', 'title' => 'Conflict']], 'meta' => ['request_id' => 'r1']]);
});

it('can be returned from a controller', function () {
    Route::get('api/manual', fn () => ApiErrorResponse::make(ApiError::make(409, 'Locked.'))->headers(['X-Test' => '1']));

    $this->getJson('api/manual')->assertStatus(409)->assertHeader('X-Test', '1')
        ->assertJsonPath('errors.0.detail', 'Locked.');
});
