<?php

declare(strict_types=1);

namespace Zhylon\ApiResources\Http\Resources;

/**
 * Builds the top-level `included` array from already rendered resources.
 *
 * Each resource using `BuildsApiResource` remembers the related resources it rendered for its
 * relationships. This walks them (including nested ones, e.g. `site.server`), de-duplicates by
 * `type` + `id`, and leaves out anything that's already part of the primary `data`.
 */
final class CompoundDocument
{
    /**
     * @param  iterable<mixed>  $primary  the resources in the response's primary `data`, after they were resolved
     * @return list<array<string, mixed>>
     */
    public static function included(iterable $primary): array
    {
        $seen = [];
        $queue = [];

        foreach ($primary as $resource) {
            if (! is_object($resource) || ! method_exists($resource, 'apiIncludedResources') || ! method_exists($resource, 'apiDocument')) {
                continue;
            }

            if (null !== ($key = self::key($resource->apiDocument()))) {
                $seen[$key] = true;
            }

            array_push($queue, ...$resource->apiIncludedResources());
        }

        $included = [];

        while ([] !== $queue) {
            ['resource' => $resource, 'document' => $document] = array_shift($queue);

            if (null !== ($key = self::key($document))) {
                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
            }

            $included[] = $document;

            if (is_object($resource) && method_exists($resource, 'apiIncludedResources')) {
                array_push($queue, ...$resource->apiIncludedResources());
            }
        }

        return $included;
    }

    /**
     * @param  array<string, mixed>|null  $document
     */
    private static function key(?array $document): ?string
    {
        if (! isset($document['type'], $document['id'])) {
            return null;
        }

        return $document['type'].':'.$document['id'];
    }
}
