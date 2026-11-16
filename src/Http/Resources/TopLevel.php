<?php

declare(strict_types=1);

namespace Zhylon\ApiResources\Http\Resources;

use Closure;
use Illuminate\Http\Resources\PotentiallyMissing;

/**
 * Assembles the extra top-level members of a response (`included`, `links`, `meta`).
 *
 * @internal
 */
final class TopLevel
{
    /**
     * @param  list<array<string, mixed>>  $included
     * @param  array<string, string|array<string, mixed>|Closure|PotentiallyMissing|null>  $links
     * @param  list<array<string, mixed>|Closure>  $meta
     * @return array<string, mixed>
     */
    public static function members(array $included, array $links, array $meta): array
    {
        $members = [];

        if ([] !== $included) {
            $members['included'] = $included;
        }

        if ([] !== ($links = Link::formatAll($links))) {
            $members['links'] = $links;
        }

        $meta = array_merge(...array_map(fn ($entry) => value($entry), $meta));

        if ([] !== $meta) {
            $members['meta'] = $meta;
        }

        return $members;
    }
}
