<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Infrastructure\HttpClient;

final class PathSanitizer
{
    private const string UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    private const string LONG_HEX_PATTERN = '/^[0-9a-f]{24,}$/i';

    private const string DIGITS_PATTERN = '/^\d+$/';

    public static function sanitize(string $path): string
    {
        if ($path === '' || $path === '/') {
            return '/';
        }

        $segments = explode('/', $path);
        foreach ($segments as $i => $segment) {
            if ($segment === '') {
                continue;
            }
            if (preg_match(self::UUID_PATTERN, $segment) === 1) {
                $segments[$i] = ':uuid';
                continue;
            }
            if (preg_match(self::DIGITS_PATTERN, $segment) === 1) {
                $segments[$i] = ':id';
                continue;
            }
            if (preg_match(self::LONG_HEX_PATTERN, $segment) === 1) {
                $segments[$i] = ':hash';
            }
        }

        return implode('/', $segments);
    }
}
