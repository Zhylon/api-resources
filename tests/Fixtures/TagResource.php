<?php

declare(strict_types=1);

namespace Zhylon\ApiResources\Tests\Fixtures;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Zhylon\ApiResources\Http\Resources\BuildsApiResource;

class TagResource extends JsonResource
{
    use BuildsApiResource;

    public function toArray(Request $request): array
    {
        return $this->toApiResource(type: 'tag', attributes: ['label' => $this->label]);
    }
}
