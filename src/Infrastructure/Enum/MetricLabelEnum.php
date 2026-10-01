<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Infrastructure\Enum;

use Msstc4Symfony\MetricsBundle\Infrastructure\Entity\Label;

enum MetricLabelEnum: string implements MetricLabelEnumInterface
{
    private const array HTTP_METHODS = ['GET', 'POST', 'DELETE', 'PUT', 'PATCH', 'HEAD'];

    private const array HTTP_METHODS_EXTENDED = ['GET', 'POST', 'DELETE', 'PUT', 'PATCH', 'HEAD', 'OPTIONS'];

    private const array DOCTRINE_QUERY_TYPES = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'OTHER'];

    private const array ERROR_LEVEL_NAMES = [
        'EMERGENCY',
        'ALERT',
        'CRITICAL',
        'ERROR',
        'WARNING',
        'NOTICE',
    ];

    case CONSOLE_COMMAND_START = 'console_command_start';
    case CONSOLE_COMMAND_FINISH = 'console_command_finish';
    case CONSOLE_COMMAND_DURATION_HISTOGRAM_SECONDS = 'console_command_duration_histogram_seconds';
    case HTTP_REQUEST = 'http_request';
    case HTTP_RESPONSE = 'http_response';
    case REQUEST_DURATION_HISTOGRAM_SECONDS = 'request_duration_histogram_seconds';
    case REQUEST_DURATION_SUMMARY_SECONDS = 'request_duration_summary_seconds';
    case EXCEPTION = 'exception';
    case ERROR = 'error';
    case HTTP_CONNECTION_REQUEST = 'http_connection_request';
    case HTTP_CONNECTION_RESPONSE = 'http_connection_response';
    case HTTP_CONNECTION_DURATION_HISTOGRAM_SECONDS = 'http_connection_duration_histogram_seconds';
    case DOCTRINE_QUERY_EXECUTE = 'doctrine_query_execute';
    case DOCTRINE_QUERY_DURATION_HISTOGRAM_SECONDS = 'doctrine_query_duration_histogram_seconds';
    case MONGODB_COMMAND_SUCCESS = 'mongodb_command_success';
    case MONGODB_COMMAND_FAILED = 'mongodb_command_failed';
    case MONGODB_COMMAND_DURATION_HISTOGRAM_SECONDS = 'mongodb_command_duration_histogram_seconds';
    case ELASTICA_REQUEST_SUCCESS = 'elastica_request_success';
    case ELASTICA_REQUEST_FAILED = 'elastica_request_failed';
    case ELASTICA_REQUEST_DURATION_HISTOGRAM_SECONDS = 'elastica_request_duration_histogram_seconds';
    case INFO_CPU_LOAD = 'info_cpu_load';
    case INFO_MEMORY_USED = 'info_memory_used';
    case INFO_FILESYSTEM_USED = 'info_filesystem_used';
    case INFO_OPCACHE_MEMORY_USED = 'info_opcache_memory_used';
    case INFO_OPCACHE_MEMORY_WASTED = 'info_opcache_memory_wasted';
    case INFO_OPCACHE_CACHED_SCRIPTS = 'info_opcache_cached_scripts';
    case INFO_OPCACHE_HIT_RATE = 'info_opcache_hit_rate';
    case INFO_FPM_IDLE_PROCESSES = 'info_fpm_idle_processes';
    case INFO_FPM_ACTIVE_PROCESSES = 'info_fpm_active_processes';
    case INFO_FPM_TOTAL_PROCESSES = 'info_fpm_total_processes';
    case INFO_FPM_MAX_ACTIVE_PROCESSES = 'info_fpm_max_active_processes';
    case INFO_FPM_LISTEN_QUEUE = 'info_fpm_listen_queue';
    case INFO_FPM_MAX_LISTEN_QUEUE = 'info_fpm_max_listen_queue';
    case INFO_FPM_LISTEN_QUEUE_SIZE = 'info_fpm_listen_queue_size';
    case INFO_CACHE_WARMUP_TIME = 'info_cache_warmup_time';

    /**
     * @deprecated since 1.2, declared by msstc4symfony/metrics-bridge-profiling; removed in 2.0
     */
    case PROFILING_SPAN_DURATION_HISTOGRAM_SECONDS = 'profiling_span_duration_histogram_seconds';

    public function getType(): MetricTypeEnum
    {
        return match ($this) {
            self::CONSOLE_COMMAND_DURATION_HISTOGRAM_SECONDS,
            self::REQUEST_DURATION_HISTOGRAM_SECONDS,
            self::HTTP_CONNECTION_DURATION_HISTOGRAM_SECONDS,
            self::MONGODB_COMMAND_DURATION_HISTOGRAM_SECONDS,
            self::ELASTICA_REQUEST_DURATION_HISTOGRAM_SECONDS,
            self::PROFILING_SPAN_DURATION_HISTOGRAM_SECONDS,
            self::DOCTRINE_QUERY_DURATION_HISTOGRAM_SECONDS => MetricTypeEnum::HISTOGRAM,

            self::REQUEST_DURATION_SUMMARY_SECONDS => MetricTypeEnum::SUMMARY,

            self::INFO_CPU_LOAD,
            self::INFO_MEMORY_USED,
            self::INFO_FILESYSTEM_USED,
            self::INFO_OPCACHE_MEMORY_USED,
            self::INFO_OPCACHE_MEMORY_WASTED,
            self::INFO_OPCACHE_CACHED_SCRIPTS,
            self::INFO_OPCACHE_HIT_RATE,
            self::INFO_FPM_IDLE_PROCESSES,
            self::INFO_FPM_ACTIVE_PROCESSES,
            self::INFO_FPM_TOTAL_PROCESSES,
            self::INFO_FPM_MAX_ACTIVE_PROCESSES,
            self::INFO_FPM_MAX_LISTEN_QUEUE,
            self::INFO_FPM_LISTEN_QUEUE_SIZE,
            self::INFO_CACHE_WARMUP_TIME,
            self::INFO_FPM_LISTEN_QUEUE => MetricTypeEnum::GAUGE,

            default => MetricTypeEnum::COUNTER,
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::CONSOLE_COMMAND_START => 'Console command starts count',
            self::CONSOLE_COMMAND_FINISH => 'Console command finishes count',
            self::CONSOLE_COMMAND_DURATION_HISTOGRAM_SECONDS => 'Console command execution duration in seconds',

            self::HTTP_REQUEST => 'Incoming HTTP request count',
            self::HTTP_RESPONSE => 'Incoming HTTP response count',
            self::REQUEST_DURATION_HISTOGRAM_SECONDS => 'Incoming HTTP request duration in seconds',
            self::REQUEST_DURATION_SUMMARY_SECONDS => 'Incoming HTTP request duration in seconds (summary)',

            self::EXCEPTION => 'Non-caught exceptions count',
            self::ERROR => 'Errors count',

            self::HTTP_CONNECTION_REQUEST => 'HTTP external connection requests count',
            self::HTTP_CONNECTION_RESPONSE => 'HTTP external connection responses count',
            self::HTTP_CONNECTION_DURATION_HISTOGRAM_SECONDS => 'HTTP external connection duration in seconds',

            self::DOCTRINE_QUERY_EXECUTE => 'Doctrine DBAL query executions count',
            self::DOCTRINE_QUERY_DURATION_HISTOGRAM_SECONDS => 'Doctrine DBAL query duration in seconds',

            self::MONGODB_COMMAND_SUCCESS => 'MongoDB command success execution count',
            self::MONGODB_COMMAND_FAILED => 'MongoDB command failed execution count',
            self::MONGODB_COMMAND_DURATION_HISTOGRAM_SECONDS => 'MongoDB command duration in seconds',

            self::ELASTICA_REQUEST_SUCCESS => 'Elastica request success execution count',
            self::ELASTICA_REQUEST_FAILED => 'Elastica request failed execution count',
            self::ELASTICA_REQUEST_DURATION_HISTOGRAM_SECONDS => 'Elastica request duration in seconds',

            self::INFO_CPU_LOAD => 'CPU load rate',
            self::INFO_MEMORY_USED => 'Memory used rate',
            self::INFO_FILESYSTEM_USED => 'Filesystem used rate',
            self::INFO_OPCACHE_MEMORY_USED => 'OPcache memory used rate',
            self::INFO_OPCACHE_MEMORY_WASTED => 'OPcache memory wasted rate',
            self::INFO_OPCACHE_CACHED_SCRIPTS => 'OPcache cached scripts count',
            self::INFO_OPCACHE_HIT_RATE => 'OPcache cache hit rate',
            self::INFO_FPM_IDLE_PROCESSES => 'FPM idle processes count',
            self::INFO_FPM_ACTIVE_PROCESSES => 'FPM active processes count',
            self::INFO_FPM_TOTAL_PROCESSES => 'FPM total processes count',
            self::INFO_FPM_MAX_ACTIVE_PROCESSES => 'FPM max. active processes count',
            self::INFO_FPM_LISTEN_QUEUE => 'FPM current listen queue length',
            self::INFO_FPM_MAX_LISTEN_QUEUE => 'FPM max. listen queue length',
            self::INFO_FPM_LISTEN_QUEUE_SIZE => 'FPM listen queue size',
            self::INFO_CACHE_WARMUP_TIME => 'Last cache warmup timestamp',

            self::PROFILING_SPAN_DURATION_HISTOGRAM_SECONDS => 'Profiling span duration in seconds',
        };
    }

    public function getLabels(): array
    {
        return match ($this) {
            self::CONSOLE_COMMAND_START,
            self::CONSOLE_COMMAND_FINISH,
            self::CONSOLE_COMMAND_DURATION_HISTOGRAM_SECONDS => [
                new Label('command', MetricLabelTypeEnum::STRING, 'Command name'),
            ],

            self::HTTP_REQUEST,
            self::REQUEST_DURATION_HISTOGRAM_SECONDS,
            self::REQUEST_DURATION_SUMMARY_SECONDS => [
                new Label('method', MetricLabelTypeEnum::ENUM, 'HTTP method', self::HTTP_METHODS),
                new Label('route', MetricLabelTypeEnum::STRING, 'Route name or URL path'),
            ],
            self::HTTP_RESPONSE => [
                new Label('method', MetricLabelTypeEnum::ENUM, 'HTTP method', self::HTTP_METHODS),
                new Label('route', MetricLabelTypeEnum::STRING, 'Route name or URL path'),
                new Label('status', MetricLabelTypeEnum::INTEGER, 'HTTP response status code'),
            ],

            self::EXCEPTION => [
                new Label('class', MetricLabelTypeEnum::STRING, 'Exception class'),
            ],
            self::ERROR => [
                new Label('level', MetricLabelTypeEnum::ENUM, 'Level code', self::ERROR_LEVEL_NAMES),
            ],

            self::HTTP_CONNECTION_REQUEST,
            self::HTTP_CONNECTION_DURATION_HISTOGRAM_SECONDS => [
                new Label('method', MetricLabelTypeEnum::ENUM, 'HTTP method', self::HTTP_METHODS_EXTENDED),
                new Label('host', MetricLabelTypeEnum::STRING, 'Request host'),
                new Label('path', MetricLabelTypeEnum::STRING, 'Request path'),
            ],
            self::HTTP_CONNECTION_RESPONSE => [
                new Label('method', MetricLabelTypeEnum::ENUM, 'HTTP method', self::HTTP_METHODS_EXTENDED),
                new Label('host', MetricLabelTypeEnum::STRING, 'Request host'),
                new Label('path', MetricLabelTypeEnum::STRING, 'Request path'),
                new Label('status', MetricLabelTypeEnum::INTEGER, 'Response status code'),
            ],

            self::DOCTRINE_QUERY_EXECUTE,
            self::DOCTRINE_QUERY_DURATION_HISTOGRAM_SECONDS => [
                new Label('connection', MetricLabelTypeEnum::STRING, 'RDBMS host + DB name'),
                new Label('type', MetricLabelTypeEnum::ENUM, 'Query type', self::DOCTRINE_QUERY_TYPES),
                new Label('table', MetricLabelTypeEnum::STRING, 'Table name'),
            ],

            self::MONGODB_COMMAND_SUCCESS,
            self::MONGODB_COMMAND_FAILED,
            self::MONGODB_COMMAND_DURATION_HISTOGRAM_SECONDS => [
                new Label('command', MetricLabelTypeEnum::STRING, 'Mongodb command'),
                new Label('host', MetricLabelTypeEnum::STRING, 'Mongodb host'),
            ],

            self::ELASTICA_REQUEST_SUCCESS,
            self::ELASTICA_REQUEST_FAILED,
            self::ELASTICA_REQUEST_DURATION_HISTOGRAM_SECONDS => [
                new Label('method', MetricLabelTypeEnum::STRING, 'Request method'),
                new Label('path', MetricLabelTypeEnum::STRING, 'Request path'),
            ],

            self::PROFILING_SPAN_DURATION_HISTOGRAM_SECONDS => [
                new Label('message', MetricLabelTypeEnum::STRING, 'Profiling span message'),
            ],

            default => [],
        };
    }

    public function getBatches(): array
    {
        return match ($this) {
            self::CONSOLE_COMMAND_DURATION_HISTOGRAM_SECONDS => [1, 5, 10, 60, 600, 3600, 86400],
            self::REQUEST_DURATION_HISTOGRAM_SECONDS => [0.1, 0.5, 1, 3, 5, 10, 15, 30, 45, 60],
            self::HTTP_CONNECTION_DURATION_HISTOGRAM_SECONDS => [0.1, 0.5, 1, 3, 5, 10, 15, 30, 45, 60],
            self::DOCTRINE_QUERY_DURATION_HISTOGRAM_SECONDS => [0.1, 0.5, 1, 3, 5, 10, 15, 30, 45, 60],
            self::MONGODB_COMMAND_DURATION_HISTOGRAM_SECONDS => [0.1, 0.5, 1, 3, 5, 10, 15, 30, 45, 60],
            self::ELASTICA_REQUEST_DURATION_HISTOGRAM_SECONDS => [0.1, 0.5, 1, 3, 5, 10, 15, 30, 45, 60],
            self::PROFILING_SPAN_DURATION_HISTOGRAM_SECONDS => [0.1, 0.5, 1, 3, 5, 10, 15, 30, 60, 180],
            default => [],
        };
    }
}
