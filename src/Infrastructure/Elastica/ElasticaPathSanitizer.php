<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Infrastructure\Elastica;

use Msstc4Symfony\MetricsBundle\Infrastructure\HttpClient\PathSanitizer;

/**
 * Bounds the "path" label of Elastica requests: the id of a document endpoint becomes ":id", then the generic rules apply.
 *
 * @internal
 */
final class ElasticaPathSanitizer
{
    private const array DOCUMENT_ENDPOINTS = ['_doc', '_create', '_update', '_source', '_explain', '_termvectors'];

    public static function sanitize(string $path): string
    {
        if ($path === '') {
            return '';
        }

        $segments = explode('/', $path);
        $last = \count($segments) - 1;
        for ($i = 1; $i < $last; $i++) {
            if ($segments[$i - 1] !== '' && $segments[$i + 1] !== '' && \in_array($segments[$i], self::DOCUMENT_ENDPOINTS, true)) {
                $segments[$i + 1] = ':id';
            }
        }

        return PathSanitizer::sanitize(implode('/', $segments));
    }
}
