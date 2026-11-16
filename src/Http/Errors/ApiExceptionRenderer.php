<?php

declare(strict_types=1);

namespace Zhylon\ApiResources\Http\Errors;

use Closure;
use Throwable;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Renders exceptions as `{ "errors": [...] }` responses. Register it in `bootstrap/app.php`:
 *
 * ```php
 * ->withExceptions(function (Exceptions $exceptions) {
 *     $exceptions->render(new ApiExceptionRenderer);
 * })
 * ```
 *
 * Out of the box it handles validation (one error per message, with `source.pointer` and the failed
 * rule as `code`), authentication (401), HTTP exceptions incl. their headers (404, 403, 429 …), and
 * everything else as 500 (with debug details in `meta` only when `app.debug` is on). Exceptions
 * implementing `ProvidesApiErrors` render themselves, and `map()` adds or overrides handling per class.
 *
 * It only kicks in for requests that expect JSON or hit `api/*`; change that with `when()`.
 */
class ApiExceptionRenderer
{
    protected ?Closure $condition = null;

    /** @var array<class-string<Throwable>, Closure> */
    protected array $mappings = [];

    protected string $pointerPrefix = '';

    protected ?Closure $pointerResolver = null;

    protected bool $ruleCodes = true;

    protected ?bool $debug = null;

    /**
     * Decides whether a request gets API errors at all: `fn (Request $request, Throwable $e) => bool`.
     */
    public function when(callable $condition): static
    {
        $this->condition = Closure::fromCallable($condition);

        return $this;
    }

    /**
     * Custom handling for an exception class (and its subclasses). The callback receives the
     * exception and the request and returns an `ApiError`, a list of them, an `ApiErrorResponse`,
     * or `null` to fall back to the default handling.
     *
     * @param  class-string<Throwable>  $exception
     */
    public function map(string $exception, callable $using): static
    {
        $this->mappings[$exception] = Closure::fromCallable($using);

        return $this;
    }

    /**
     * Prefix for validation pointers, e.g. `/data/attributes` for JSON:API request documents.
     * Default: none, so `address.city` becomes `/address/city` — pointing into the body as sent.
     */
    public function pointerPrefix(string $prefix): static
    {
        $this->pointerPrefix = rtrim($prefix, '/');

        return $this;
    }

    /**
     * Fully custom pointers: `fn (string $field) => string|null` (`null` omits `source`).
     */
    public function pointerUsing(callable $resolver): static
    {
        $this->pointerResolver = Closure::fromCallable($resolver);

        return $this;
    }

    /**
     * Whether validation errors get the failed rule as `code` (e.g. `required`, `max`). Default: on.
     */
    public function withRuleCodes(bool $enabled = true): static
    {
        $this->ruleCodes = $enabled;

        return $this;
    }

    /**
     * Forces debug details (exception class, file, line, trace in `meta`) on or off.
     * Default: `config('app.debug')`.
     */
    public function debug(?bool $debug = true): static
    {
        $this->debug = $debug;

        return $this;
    }

    public function __invoke(Throwable $e, Request $request): mixed
    {
        if (! $this->shouldRender($request, $e)) {
            return null;
        }

        $response = $this->toErrorResponse($e, $request);

        return $response?->toResponse($request);
    }

    /**
     * Converts an exception to an `ApiErrorResponse`, or `null` to leave it to Laravel.
     */
    public function toErrorResponse(Throwable $e, Request $request): ?ApiErrorResponse
    {
        foreach ($this->mappings as $class => $using) {
            if ($e instanceof $class && null !== ($mapped = $using($e, $request))) {
                return $this->normalize($mapped);
            }
        }

        return match (true) {
            $e instanceof ProvidesApiErrors       => $this->normalize($e->toApiErrors($request)),
            $e instanceof HttpResponseException   => null,
            $e instanceof ValidationException     => $this->fromValidation($e),
            $e instanceof AuthenticationException => ApiErrorResponse::make(ApiError::make(401, $e->getMessage())),
            $e instanceof HttpExceptionInterface  => $this->fromHttpException($e),
            default                               => $this->fromThrowable($e),
        };
    }

    protected function shouldRender(Request $request, Throwable $e): bool
    {
        if (null !== $this->condition) {
            return (bool) ($this->condition)($request, $e);
        }

        return $request->expectsJson() || $request->is('api/*');
    }

    protected function fromValidation(ValidationException $e): ApiErrorResponse
    {
        $failed = $this->ruleCodes ? $e->validator->failed() : [];
        $response = ApiErrorResponse::make()->status($e->status);

        foreach ($e->errors() as $field => $messages) {
            $rules = array_keys($failed[$field] ?? []);
            $aligned = count($rules) === count($messages);

            foreach (array_values($messages) as $index => $message) {
                $error = ApiError::make($e->status, $message);

                if ($aligned) {
                    $error->code($this->ruleCode($rules[$index]));
                }

                if (null !== ($pointer = $this->pointer((string) $field))) {
                    $error->pointer($pointer);
                }

                $response->add($error);
            }
        }

        return $response;
    }

    protected function fromHttpException(HttpExceptionInterface $e): ApiErrorResponse
    {
        $status = $e->getStatusCode();
        $detail = '' === $e->getMessage() ? null : $e->getMessage();

        // Laravel turns ModelNotFoundException into a 404 whose message leaks the model class.
        if ($e->getPrevious() instanceof ModelNotFoundException && ! $this->isDebug()) {
            $detail = 'The requested resource was not found.';
        }

        $error = ApiError::make($status, $detail);

        if ($status >= 500) {
            $error->detail($this->isDebug() ? $detail : null);
        }

        if ($this->isDebug()) {
            $error->meta($this->debugMeta($e->getPrevious() ?? $e));
        }

        return ApiErrorResponse::make($error)->headers($e->getHeaders());
    }

    protected function fromThrowable(Throwable $e): ApiErrorResponse
    {
        $error = ApiError::make(500);

        if ($this->isDebug()) {
            $error->detail($e->getMessage())->meta($this->debugMeta($e));
        }

        return ApiErrorResponse::make($error);
    }

    protected function normalize(ApiError|ApiErrorResponse|array $errors): ApiErrorResponse
    {
        return match (true) {
            $errors instanceof ApiErrorResponse => $errors,
            $errors instanceof ApiError         => ApiErrorResponse::make($errors),
            default                             => ApiErrorResponse::make(...array_values($errors)),
        };
    }

    protected function pointer(string $field): ?string
    {
        if (null !== $this->pointerResolver) {
            return ($this->pointerResolver)($field);
        }

        // JSON pointer escaping: "~" → "~0", "/" → "~1".
        $segments = array_map(
            fn ($segment) => str_replace(['~', '/'], ['~0', '~1'], $segment),
            explode('.', $field),
        );

        return $this->pointerPrefix.'/'.implode('/', $segments);
    }

    /**
     * `Required` → `required`, `App\Rules\Uppercase` → `uppercase`.
     */
    protected function ruleCode(string $rule): string
    {
        return Str::snake(class_basename($rule));
    }

    protected function isDebug(): bool
    {
        return $this->debug ?? (bool) config('app.debug');
    }

    protected function debugMeta(Throwable $e): array
    {
        return [
            'exception' => $e::class,
            'message'   => $e->getMessage(),
            'file'      => $e->getFile(),
            'line'      => $e->getLine(),
            'trace'     => array_slice(array_map(
                fn (array $frame) => ($frame['file'] ?? '[internal]').':'.($frame['line'] ?? 0),
                $e->getTrace(),
            ), 0, 10),
        ];
    }
}
