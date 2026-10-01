<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Infrastructure\HttpClient;

use Closure;
use Override;
use php_user_filter;
use StreamBucket;

/**
 * Pass-through read filter that reports the end of the body. Lets toStream() keep the inner,
 * buffered and rewindable stream while still recording the transfer duration.
 *
 * @internal attached by MonitoredResponse::toStream() only
 */
final class CompletionStreamFilter extends php_user_filter
{
    public const string NAME = 'msstc4symfony.metrics.completion';

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
        while (($bucket = stream_bucket_make_writeable($in)) instanceof StreamBucket) {
            $consumed += $bucket->datalen;
            stream_bucket_append($out, $bucket);
        }

        // Fires on EOF and on fclose(); recordCompletion() is idempotent.
        if ($closing && $this->params instanceof Closure) {
            ($this->params)();
        }

        return PSFS_PASS_ON;
    }
}
