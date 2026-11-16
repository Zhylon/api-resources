<?php

declare(strict_types=1);

namespace Zhylon\ApiResources\Tests\Fixtures\Eloquent;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    protected $guarded = [];

    public function safeHashID(): string
    {
        return 'user-'.$this->getKey();
    }
}
