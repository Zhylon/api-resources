<?php

declare(strict_types=1);

use Zhylon\ApiResources\Tests\Fixtures\Eloquent\Tag;
use Zhylon\ApiResources\Tests\Fixtures\PostResource;
use Zhylon\ApiResources\Tests\Fixtures\Eloquent\Post;
use Zhylon\ApiResources\Tests\Fixtures\Eloquent\User;

function post(array $relations = [], int $id = 1): Post
{
    $post = new Post(['id' => $id, 'title' => 'Hello', 'user_id' => 5]);

    foreach ($relations as $name => $value) {
        $post->setRelation($name, $value);
    }

    return $post;
}

function user(int $id = 5, array $relations = []): User
{
    $user = new User(['id' => $id, 'name' => 'Ada']);

    foreach ($relations as $name => $value) {
        $user->setRelation($name, $value);
    }

    return $user;
}

function tag(int $id, string $label = 'php'): Tag
{
    return new Tag(['id' => $id, 'label' => $label]);
}

function render(Post $post): array
{
    return (new PostResource($post))->resolve(request());
}

function respond(mixed $resource): array
{
    return $resource->response(request())->getData(true);
}
