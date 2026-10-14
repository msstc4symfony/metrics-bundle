<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Infrastructure\HttpClient;

use Closure;
use Override;
use php_user_filter;

/**
 * Pass-through read filter that reports the end of the body. Lets toStream() keep the inner,
 * buffered and rewindable stream while still recording the transfer duration.
 *
 * @internal attached by MonitoredResponse::toStream() only
 */
final class CompletionStreamFilter extends php_user_filter
{
    public const NAME = 'msstc4symfony.metrics.completion';

    public static function attach(mixed $stream, Closure $onClosing): void
    {
        if (!in_array(self::NAME, stream_get_filters(), true)) {
            stream_filter_register(self::NAME, self::class);
        }

        if (is_resource($stream)) {
            stream_filter_append($stream, self::NAME, STREAM_FILTER_READ, $onClosing);
        }
    }

    /**
     * @param resource $in
     * @param resource $out
     * @param int $consumed
     */
    #[Override]
    public function filter($in, $out, &$consumed, bool $closing): int
    {
        // Before PHP 8.4 the bucket is a plain object, not a StreamBucket: no instanceof here.
        while (is_object($bucket = stream_bucket_make_writeable($in))) {
            $length = $bucket->datalen;
            $consumed += is_int($length) ? $length : 0;
            stream_bucket_append($out, $bucket);
        }

        // Fires on EOF and on fclose(); recordCompletion() is idempotent.
        if ($closing && $this->params instanceof Closure) {
            ($this->params)();
        }

        return PSFS_PASS_ON;
    }
}
