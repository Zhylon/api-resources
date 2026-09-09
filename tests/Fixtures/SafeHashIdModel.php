<?php

declare(strict_types=1);

namespace Zhylon\ApiResources\Tests\Fixtures;

use Illuminate\Support\Carbon;

class SafeHashIdModel
{
    public function __construct(
        public int $id,
        public string $status,
        private string $hash,
        public ?Carbon $created_at = null,
        public ?Carbon $updated_at = null,
    ) {}

    public function getKey(): int
    {
        return $this->id;
    }

    public function safeHashID(): string
    {
        return $this->hash;
    }
}
