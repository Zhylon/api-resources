<?php

declare(strict_types=1);

namespace Zhylon\ApiResources\Tests\Fixtures;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Zhylon\ApiResources\Http\Resources\BuildsApiResource;

class UserResource extends JsonResource
{
    use BuildsApiResource;

    public static ?Closure $relationships = null;

    public static function using(Closure $relationships): void
    {
        static::$relationships = $relationships;
    }

    public function toArray(Request $request): array
    {
        return $this->toApiResource(
            type: 'user',
            attributes: ['name' => $this->name],
            relationships: static::$relationships ? (static::$relationships)->call($this) : [],
        );
    }
}
