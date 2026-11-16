<?php

declare(strict_types=1);

namespace Zhylon\ApiResources\Tests\Fixtures;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Zhylon\ApiResources\Http\Resources\BuildsApiResource;

/**
 * @property int $id
 * @property string $status
 */
class OrderResource extends JsonResource
{
    use BuildsApiResource;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->toApiResource(
            type: 'order',
            attributes: ['status' => $this->status],
            selfHref: '/orders/'.$this->id,
            timestamps: true,
        );
    }
}
