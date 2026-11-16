<?php

declare(strict_types=1);

namespace Zhylon\ApiResources\Http\Errors;

use JsonSerializable;
use Illuminate\Contracts\Support\Arrayable;
use Zhylon\ApiResources\Http\Resources\Link;
use Symfony\Component\HttpFoundation\Response;

/**
 * A single JSON:API-style error object:
 *
 * ```json
 * { "id": "...", "status": "422", "code": "required", "title": "Unprocessable Content",
 *   "detail": "The name field is required.", "source": { "pointer": "/name" },
 *   "links": { "about": { "href": "..." } }, "meta": { ... } }
 * ```
 *
 * Empty members are omitted. `status` is rendered as a string, as the JSON:API spec requires.
 *
 * @implements Arrayable<string, mixed>
 *
 * @phpstan-consistent-constructor
 */
class ApiError implements Arrayable, JsonSerializable
{
    protected ?string $id = null;

    protected ?string $code = null;

    protected ?string $title;

    protected ?string $detail;

    /**
     * @var array<string, string>
     */
    protected array $source = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    protected array $links = [];

    /**
     * @var array<string, mixed>
     */
    protected array $meta = [];

    public function __construct(protected int $status = 500, ?string $title = null, ?string $detail = null)
    {
        $this->title = $title ?? (Response::$statusTexts[$status] ?? null);
        $this->detail = $detail;
    }

    /**
     * The title defaults to the HTTP status text, e.g. `Not Found` for 404.
     */
    public static function make(int $status = 500, ?string $detail = null, ?string $title = null): static
    {
        return new static($status, $title, $detail);
    }

    public function status(int $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function id(?string $id): static
    {
        $this->id = $id;

        return $this;
    }

    /**
     * An application-specific, machine-readable error code, e.g. `required` or `site_locked`.
     */
    public function code(?string $code): static
    {
        $this->code = $code;

        return $this;
    }

    public function title(?string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function detail(?string $detail): static
    {
        $this->detail = $detail;

        return $this;
    }

    /**
     * JSON pointer into the request document, e.g. `/name` or `/data/attributes/name`.
     */
    public function pointer(string $pointer): static
    {
        $this->source['pointer'] = $pointer;

        return $this;
    }

    /**
     * The query parameter that caused the error, e.g. `filter[status]`.
     */
    public function parameter(string $parameter): static
    {
        $this->source['parameter'] = $parameter;

        return $this;
    }

    public function header(string $header): static
    {
        $this->source['header'] = $header;

        return $this;
    }

    /**
     * Adds a link, e.g. `about` (documentation of this occurrence) or `type` (of this kind of error).
     *
     * @param  string|array<string, mixed>|null  $href
     * @param  array<string, mixed>  $meta
     */
    public function link(string $name, string|array|null $href, array $meta = []): static
    {
        if (null !== ($link = Link::format($href, $meta))) {
            $this->links[$name] = $link;
        }

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
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'id'     => $this->id,
            'status' => (string) $this->status,
            'code'   => $this->code,
            'title'  => $this->title,
            'detail' => $this->detail,
            'source' => $this->source,
            'links'  => $this->links,
            'meta'   => $this->meta,
        ], fn ($value) => null !== $value && [] !== $value);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
