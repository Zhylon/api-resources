<?php

declare(strict_types=1);

namespace TobyMaxham\HashId\Interfaces;

/**
 * Test double for tobymaxham/laravel-hashid's interface of the same name,
 * used to exercise BuildsApiResource::resolveResourceId() without requiring
 * that package as an actual dependency.
 */
interface Hashing
{
    public function hashID();
}
