<?php

declare(strict_types=1);

namespace Zhylon\ApiResources\Http\Resources;

use Closure;
use Illuminate\Http\Resources\PotentiallyMissing;

/**
 * Formats links the same way everywhere in the package: `{ "href": "..." }`, optionally with `meta`.
 */
final class Link
{
    /**
     * `$href` may be a URL string, a ready-made link array (passed through), or `null`.
     */
    public static function format(string|array|null $href, array $meta = []): ?array
    {
        if (null === $href) {
            return null;
        }

        if (is_array($href)) {
            return [] === $meta ? $href : [...$href, 'meta' => $meta];
        }

        return [] === $meta ? ['href' => $href] : ['href' => $href, 'meta' => $meta];
    }

    /**
     * Formats a map of links. Values may be strings, link arrays, `Closure`s returning either, or
     * `null`/`MissingValue` — those links are dropped.
     *
     * @param  array<string, string|array|Closure|PotentiallyMissing|null>  $links
     */
    public static function formatAll(array $links): array
    {
        $formatted = [];

        foreach ($links as $name => $href) {
            $href = $href instanceof Closure ? $href() : $href;

            if ($href instanceof PotentiallyMissing && $href->isMissing()) {
                continue;
            }

            if (null !== ($link = self::format($href))) {
                $formatted[$name] = $link;
            }
        }

        return $formatted;
    }
}
