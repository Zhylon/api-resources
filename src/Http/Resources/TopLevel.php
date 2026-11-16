<?php

declare(strict_types=1);

namespace Zhylon\ApiResources\Http\Resources;

use Closure;

/**
 * Assembles the extra top-level members of a response (`included`, `links`, `meta`).
 *
 * @internal
 */
final class TopLevel
{
    /**
     * @param  list<array>  $included
     * @param  array<string, string|array|Closure|null>  $links
     * @param  list<array|Closure>  $meta
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
