<?php

declare(strict_types=1);

namespace Zhylon\ApiResources\Http\Errors;

use Illuminate\Http\JsonResponse;
use Illuminate\Contracts\Support\Responsable;

/**
 * An `{ "errors": [...] }` response. Can be returned from controllers directly:
 *
 * ```php
 * return ApiErrorResponse::make(
 *     ApiError::make(409, 'The site is locked.')->code('site_locked'),
 * );
 * ```
 *
 * @phpstan-consistent-constructor
 */
class ApiErrorResponse implements Responsable
{
    /** @var list<ApiError> */
    protected array $errors;

    protected ?int $status = null;

    /**
     * @var array<string, mixed>
     */
    protected array $meta = [];

    /**
     * @var array<string, string|list<string>>
     */
    protected array $headers = [];

    public function __construct(ApiError ...$errors)
    {
        $this->errors = array_values($errors);
    }

    public static function make(ApiError ...$errors): static
    {
        return new static(...$errors);
    }

    public function add(ApiError ...$errors): static
    {
        array_push($this->errors, ...$errors);

        return $this;
    }

    /**
     * Overrides the HTTP status (by default derived from the errors, see `resolveStatus()`).
     */
    public function status(int $status): static
    {
        $this->status = $status;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function meta(array $meta): static
    {
        $this->meta = [...$this->meta, ...$meta];

        return $this;
    }

    /**
     * @param  array<string, string|list<string>>  $headers
     */
    public function headers(array $headers): static
    {
        $this->headers = [...$this->headers, ...$headers];

        return $this;
    }

    /**
     * @return list<ApiError>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * The explicit status, else the errors' shared status, else the most generally applicable one
     * (400 for mixed 4xx, 500 otherwise), as recommended by JSON:API.
     */
    public function resolveStatus(): int
    {
        if (null !== $this->status) {
            return $this->status;
        }

        $statuses = array_values(array_unique(array_map(fn (ApiError $error) => $error->getStatus(), $this->errors)));

        return match (true) {
            [] === $statuses                              => 500,
            1 === count($statuses)                        => $statuses[0],
            min($statuses) >= 400 && max($statuses) < 500 => 400,
            default                                       => 500,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'errors' => array_map(fn (ApiError $error) => $error->toArray(), $this->errors),
        ];

        if ([] !== $this->meta) {
            $payload['meta'] = $this->meta;
        }

        return $payload;
    }

    public function toResponse($request): JsonResponse
    {
        return new JsonResponse($this->toArray(), $this->resolveStatus(), $this->headers);
    }
}
