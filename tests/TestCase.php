<?php

declare(strict_types=1);

namespace Zhylon\ApiResources\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Zhylon\ApiResources\Http\Resources\ResourceId;
use Zhylon\ApiResources\Http\Resources\Relationship;
use Zhylon\ApiResources\Tests\Fixtures\PostResource;
use Zhylon\ApiResources\Tests\Fixtures\UserResource;
use Zhylon\ApiResources\Http\Resources\SparseFieldsets;

abstract class TestCase extends Orchestra
{
    protected function tearDown(): void
    {
        PostResource::$relationships = null;
        UserResource::$relationships = null;
        ResourceId::flush();
        SparseFieldsets::flush();
        Relationship::maxDepth(16);

        parent::tearDown();
    }
}
