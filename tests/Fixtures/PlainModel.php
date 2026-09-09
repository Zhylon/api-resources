<?php

declare(strict_types=1);

namespace Zhylon\ApiResources\Tests\Fixtures;

use Illuminate\Support\Carbon;

class PlainModel
{
    public function __construct(
        public int $id,
        public string $status,
        public ?Carbon $created_at = null,
        public ?Carbon $updated_at = null,
    ) {}

    public function getKey(): int
    {
        return $this->id;
    }
}
