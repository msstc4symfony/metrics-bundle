<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\Application\Metric;

use Msstc4Symfony\MetricsBundle\Application\Metric\BuiltInMetric;
use Msstc4Symfony\MetricsBundle\Application\Metric\Label;
use Msstc4Symfony\MetricsBundle\Application\Metric\MetricDefinitionInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the exported metric contract: dashboards and alerts break when a type, a label or a histogram
 * bucket ("le") changes, so such a change must fail here first.
 */
final class MetricCatalogTest extends TestCase
{
    /**
     * @return iterable<string, array{MetricDefinitionInterface, string, list<string>, list<int|float>}>
     */
    public static function catalogProvider(): iterable
    {
        yield 'CONSOLE_COMMAND_START' => [BuiltInMetric::CONSOLE_COMMAND_START, 'counter', ['command:string'], []];
        yield 'CONSOLE_COMMAND_FINISH' => [BuiltInMetric::CONSOLE_COMMAND_FINISH, 'counter', ['command:string'], []];
        yield 'CONSOLE_COMMAND_DURATION_HISTOGRAM_SECONDS' => [BuiltInMetric::CONSOLE_COMMAND_DURATION_HISTOGRAM_SECONDS, 'histogram', ['command:string'], [1, 5, 10, 60, 600, 3600, 86400]];
        yield 'HTTP_REQUEST' => [BuiltInMetric::HTTP_REQUEST, 'counter', ['method:enum=GET|POST|DELETE|PUT|PATCH|HEAD', 'route:string'], []];
        yield 'HTTP_RESPONSE' => [BuiltInMetric::HTTP_RESPONSE, 'counter', ['method:enum=GET|POST|DELETE|PUT|PATCH|HEAD', 'route:string', 'status:integer'], []];
        yield 'REQUEST_DURATION_HISTOGRAM_SECONDS' => [BuiltInMetric::REQUEST_DURATION_HISTOGRAM_SECONDS, 'histogram', ['method:enum=GET|POST|DELETE|PUT|PATCH|HEAD', 'route:string'], [0.1, 0.5, 1, 3, 5, 10, 15, 30, 45, 60]];
        yield 'REQUEST_DURATION_SUMMARY_SECONDS' => [BuiltInMetric::REQUEST_DURATION_SUMMARY_SECONDS, 'summary', ['method:enum=GET|POST|DELETE|PUT|PATCH|HEAD', 'route:string'], []];
        yield 'EXCEPTION' => [BuiltInMetric::EXCEPTION, 'counter', ['class:string'], []];
        yield 'ERROR' => [BuiltInMetric::ERROR, 'counter', ['level:enum=EMERGENCY|ALERT|CRITICAL|ERROR|WARNING|NOTICE'], []];
        yield 'HTTP_CONNECTION_REQUEST' => [BuiltInMetric::HTTP_CONNECTION_REQUEST, 'counter', ['method:enum=GET|POST|DELETE|PUT|PATCH|HEAD|OPTIONS', 'host:string', 'path:string'], []];
        yield 'HTTP_CONNECTION_RESPONSE' => [BuiltInMetric::HTTP_CONNECTION_RESPONSE, 'counter', ['method:enum=GET|POST|DELETE|PUT|PATCH|HEAD|OPTIONS', 'host:string', 'path:string', 'status:integer'], []];
        yield 'HTTP_CONNECTION_DURATION_HISTOGRAM_SECONDS' => [BuiltInMetric::HTTP_CONNECTION_DURATION_HISTOGRAM_SECONDS, 'histogram', ['method:enum=GET|POST|DELETE|PUT|PATCH|HEAD|OPTIONS', 'host:string', 'path:string'], [0.1, 0.5, 1, 3, 5, 10, 15, 30, 45, 60]];
        yield 'DOCTRINE_QUERY_EXECUTE' => [BuiltInMetric::DOCTRINE_QUERY_EXECUTE, 'counter', ['connection:string', 'type:enum=SELECT|INSERT|UPDATE|DELETE|OTHER', 'table:string'], []];
        yield 'DOCTRINE_QUERY_DURATION_HISTOGRAM_SECONDS' => [BuiltInMetric::DOCTRINE_QUERY_DURATION_HISTOGRAM_SECONDS, 'histogram', ['connection:string', 'type:enum=SELECT|INSERT|UPDATE|DELETE|OTHER', 'table:string'], [0.1, 0.5, 1, 3, 5, 10, 15, 30, 45, 60]];
        yield 'MONGODB_COMMAND_SUCCESS' => [BuiltInMetric::MONGODB_COMMAND_SUCCESS, 'counter', ['command:string', 'host:string'], []];
        yield 'MONGODB_COMMAND_FAILED' => [BuiltInMetric::MONGODB_COMMAND_FAILED, 'counter', ['command:string', 'host:string'], []];
        yield 'MONGODB_COMMAND_DURATION_HISTOGRAM_SECONDS' => [BuiltInMetric::MONGODB_COMMAND_DURATION_HISTOGRAM_SECONDS, 'histogram', ['command:string', 'host:string'], [0.1, 0.5, 1, 3, 5, 10, 15, 30, 45, 60]];
        yield 'ELASTICA_REQUEST_SUCCESS' => [BuiltInMetric::ELASTICA_REQUEST_SUCCESS, 'counter', ['method:string', 'path:string'], []];
        yield 'ELASTICA_REQUEST_FAILED' => [BuiltInMetric::ELASTICA_REQUEST_FAILED, 'counter', ['method:string', 'path:string'], []];
        yield 'ELASTICA_REQUEST_DURATION_HISTOGRAM_SECONDS' => [BuiltInMetric::ELASTICA_REQUEST_DURATION_HISTOGRAM_SECONDS, 'histogram', ['method:string', 'path:string'], [0.1, 0.5, 1, 3, 5, 10, 15, 30, 45, 60]];
        yield 'INFO_CPU_LOAD' => [BuiltInMetric::INFO_CPU_LOAD, 'gauge', [], []];
        yield 'INFO_MEMORY_USED' => [BuiltInMetric::INFO_MEMORY_USED, 'gauge', [], []];
        yield 'INFO_FILESYSTEM_USED' => [BuiltInMetric::INFO_FILESYSTEM_USED, 'gauge', [], []];
        yield 'INFO_OPCACHE_MEMORY_USED' => [BuiltInMetric::INFO_OPCACHE_MEMORY_USED, 'gauge', [], []];
        yield 'INFO_OPCACHE_MEMORY_WASTED' => [BuiltInMetric::INFO_OPCACHE_MEMORY_WASTED, 'gauge', [], []];
        yield 'INFO_OPCACHE_CACHED_SCRIPTS' => [BuiltInMetric::INFO_OPCACHE_CACHED_SCRIPTS, 'gauge', [], []];
        yield 'INFO_OPCACHE_HIT_RATE' => [BuiltInMetric::INFO_OPCACHE_HIT_RATE, 'gauge', [], []];
        yield 'INFO_FPM_IDLE_PROCESSES' => [BuiltInMetric::INFO_FPM_IDLE_PROCESSES, 'gauge', [], []];
        yield 'INFO_FPM_ACTIVE_PROCESSES' => [BuiltInMetric::INFO_FPM_ACTIVE_PROCESSES, 'gauge', [], []];
        yield 'INFO_FPM_TOTAL_PROCESSES' => [BuiltInMetric::INFO_FPM_TOTAL_PROCESSES, 'gauge', [], []];
        yield 'INFO_FPM_MAX_ACTIVE_PROCESSES' => [BuiltInMetric::INFO_FPM_MAX_ACTIVE_PROCESSES, 'gauge', [], []];
        yield 'INFO_FPM_LISTEN_QUEUE' => [BuiltInMetric::INFO_FPM_LISTEN_QUEUE, 'gauge', [], []];
        yield 'INFO_FPM_MAX_LISTEN_QUEUE' => [BuiltInMetric::INFO_FPM_MAX_LISTEN_QUEUE, 'gauge', [], []];
        yield 'INFO_FPM_LISTEN_QUEUE_SIZE' => [BuiltInMetric::INFO_FPM_LISTEN_QUEUE_SIZE, 'gauge', [], []];
        yield 'INFO_CACHE_WARMUP_TIME' => [BuiltInMetric::INFO_CACHE_WARMUP_TIME, 'gauge', [], []];
        yield 'MESSENGER_MESSAGE_SENT' => [BuiltInMetric::MESSENGER_MESSAGE_SENT, 'counter', ['transport:string', 'message:string'], []];
        yield 'MESSENGER_MESSAGE_HANDLED' => [BuiltInMetric::MESSENGER_MESSAGE_HANDLED, 'counter', ['transport:string', 'message:string', 'status:enum=handled|failed|retried'], []];
        yield 'MESSENGER_MESSAGE_HANDLING_DURATION_HISTOGRAM_SECONDS' => [BuiltInMetric::MESSENGER_MESSAGE_HANDLING_DURATION_HISTOGRAM_SECONDS, 'histogram', ['transport:string', 'message:string', 'status:enum=handled|failed|retried'], [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10, 30, 60, 180]];
    }

    /**
     * @param list<string> $labels "name:type", plus "=v1|v2" for enum labels
     * @param list<int|float> $buckets
     */
    #[DataProvider('catalogProvider')]
    public function testMetricContract(MetricDefinitionInterface $metric, string $type, array $labels, array $buckets): void
    {
        self::assertSame($type, $metric->getType()->value);
        self::assertNotSame('', $metric->getDescription());
        self::assertSame($labels, array_map($this->describe(...), $metric->getLabels()));
        self::assertSame($buckets, $metric->getBatches());
    }

    public function testEveryCaseIsPinned(): void
    {
        $pinned = array_map(static fn (array $row): string => self::caseId($row[0]), iterator_to_array(self::catalogProvider(), false));
        $declared = array_map(self::caseId(...), BuiltInMetric::cases());

        // Case order is not part of the contract.
        sort($pinned);
        sort($declared);
        self::assertSame($declared, $pinned);
    }

    private static function caseId(MetricDefinitionInterface $case): string
    {
        return $case::class . '::' . $case->name;
    }

    private function describe(Label $label): string
    {
        $description = $label->name . ':' . $label->type->value;

        return $label->enums === [] ? $description : $description . '=' . implode('|', $label->enums);
    }
}
