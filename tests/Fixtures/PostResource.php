<?php

declare(strict_types=1);

namespace Zhylon\ApiResources\Tests\Fixtures;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Zhylon\ApiResources\Http\Resources\BuildsApiResource;

/**
 * Renders whatever relationships the test hands in via `using()`, so each test can describe its case inline.
 *
 * @property int $id
 * @property string $title
 */
class PostResource extends JsonResource
{
    use BuildsApiResource;

    public static ?Closure $relationships = null;

    public static function using(Closure $relationships): void
    {
        static::$relationships = $relationships;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->toApiResource(
            type: 'post',
            attributes: ['title' => $this->title],
            selfHref: '/posts/'.$this->id,
            relationships: static::$relationships ? (static::$relationships)->call($this) : [],
        );
    }
}
