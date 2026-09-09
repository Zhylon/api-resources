<?php

declare(strict_types=1);

namespace Zhylon\ApiResources\Tests\Fixtures;

use Illuminate\Support\Carbon;
use TobyMaxham\HashId\Interfaces\Hashing;

class HashingModel implements Hashing
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

    public function hashID(): string
    {
        return $this->hash;
    }
}
