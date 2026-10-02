<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\Infrastructure\Enum;

use Msstc4Symfony\MetricsBundle\Infrastructure\Entity\Label;
use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MessengerMetricLabelEnum;
use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;
use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MetricLabelEnumInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the exported metric contract: dashboards and alerts break when a type, a label or a histogram
 * bucket ("le") changes, so within major 1 such a change must fail here first.
 */
final class MetricCatalogTest extends TestCase
{
    /**
     * @return iterable<string, array{MetricLabelEnumInterface, string, list<string>, list<int|float>}>
     */
    public static function catalogProvider(): iterable
    {
        yield 'CONSOLE_COMMAND_START' => [MetricLabelEnum::CONSOLE_COMMAND_START, 'counter', ['command:string'], []];
        yield 'CONSOLE_COMMAND_FINISH' => [MetricLabelEnum::CONSOLE_COMMAND_FINISH, 'counter', ['command:string'], []];
        yield 'CONSOLE_COMMAND_DURATION_HISTOGRAM_SECONDS' => [MetricLabelEnum::CONSOLE_COMMAND_DURATION_HISTOGRAM_SECONDS, 'histogram', ['command:string'], [1, 5, 10, 60, 600, 3600, 86400]];
        yield 'HTTP_REQUEST' => [MetricLabelEnum::HTTP_REQUEST, 'counter', ['method:enum=GET|POST|DELETE|PUT|PATCH|HEAD', 'route:string'], []];
        yield 'HTTP_RESPONSE' => [MetricLabelEnum::HTTP_RESPONSE, 'counter', ['method:enum=GET|POST|DELETE|PUT|PATCH|HEAD', 'route:string', 'status:integer'], []];
        yield 'REQUEST_DURATION_HISTOGRAM_SECONDS' => [MetricLabelEnum::REQUEST_DURATION_HISTOGRAM_SECONDS, 'histogram', ['method:enum=GET|POST|DELETE|PUT|PATCH|HEAD', 'route:string'], [0.1, 0.5, 1, 3, 5, 10, 15, 30, 45, 60]];
        yield 'REQUEST_DURATION_SUMMARY_SECONDS' => [MetricLabelEnum::REQUEST_DURATION_SUMMARY_SECONDS, 'summary', ['method:enum=GET|POST|DELETE|PUT|PATCH|HEAD', 'route:string'], []];
        yield 'EXCEPTION' => [MetricLabelEnum::EXCEPTION, 'counter', ['class:string'], []];
        yield 'ERROR' => [MetricLabelEnum::ERROR, 'counter', ['level:enum=EMERGENCY|ALERT|CRITICAL|ERROR|WARNING|NOTICE'], []];
        yield 'HTTP_CONNECTION_REQUEST' => [MetricLabelEnum::HTTP_CONNECTION_REQUEST, 'counter', ['method:enum=GET|POST|DELETE|PUT|PATCH|HEAD|OPTIONS', 'host:string', 'path:string'], []];
        yield 'HTTP_CONNECTION_RESPONSE' => [MetricLabelEnum::HTTP_CONNECTION_RESPONSE, 'counter', ['method:enum=GET|POST|DELETE|PUT|PATCH|HEAD|OPTIONS', 'host:string', 'path:string', 'status:integer'], []];
        yield 'HTTP_CONNECTION_DURATION_HISTOGRAM_SECONDS' => [MetricLabelEnum::HTTP_CONNECTION_DURATION_HISTOGRAM_SECONDS, 'histogram', ['method:enum=GET|POST|DELETE|PUT|PATCH|HEAD|OPTIONS', 'host:string', 'path:string'], [0.1, 0.5, 1, 3, 5, 10, 15, 30, 45, 60]];
        yield 'DOCTRINE_QUERY_EXECUTE' => [MetricLabelEnum::DOCTRINE_QUERY_EXECUTE, 'counter', ['connection:string', 'type:enum=SELECT|INSERT|UPDATE|DELETE|OTHER', 'table:string'], []];
        yield 'DOCTRINE_QUERY_DURATION_HISTOGRAM_SECONDS' => [MetricLabelEnum::DOCTRINE_QUERY_DURATION_HISTOGRAM_SECONDS, 'histogram', ['connection:string', 'type:enum=SELECT|INSERT|UPDATE|DELETE|OTHER', 'table:string'], [0.1, 0.5, 1, 3, 5, 10, 15, 30, 45, 60]];
        yield 'MONGODB_COMMAND_SUCCESS' => [MetricLabelEnum::MONGODB_COMMAND_SUCCESS, 'counter', ['command:string', 'host:string'], []];
        yield 'MONGODB_COMMAND_FAILED' => [MetricLabelEnum::MONGODB_COMMAND_FAILED, 'counter', ['command:string', 'host:string'], []];
        yield 'MONGODB_COMMAND_DURATION_HISTOGRAM_SECONDS' => [MetricLabelEnum::MONGODB_COMMAND_DURATION_HISTOGRAM_SECONDS, 'histogram', ['command:string', 'host:string'], [0.1, 0.5, 1, 3, 5, 10, 15, 30, 45, 60]];
        yield 'ELASTICA_REQUEST_SUCCESS' => [MetricLabelEnum::ELASTICA_REQUEST_SUCCESS, 'counter', ['method:string', 'path:string'], []];
        yield 'ELASTICA_REQUEST_FAILED' => [MetricLabelEnum::ELASTICA_REQUEST_FAILED, 'counter', ['method:string', 'path:string'], []];
        yield 'ELASTICA_REQUEST_DURATION_HISTOGRAM_SECONDS' => [MetricLabelEnum::ELASTICA_REQUEST_DURATION_HISTOGRAM_SECONDS, 'histogram', ['method:string', 'path:string'], [0.1, 0.5, 1, 3, 5, 10, 15, 30, 45, 60]];
        yield 'INFO_CPU_LOAD' => [MetricLabelEnum::INFO_CPU_LOAD, 'gauge', [], []];
        yield 'INFO_MEMORY_USED' => [MetricLabelEnum::INFO_MEMORY_USED, 'gauge', [], []];
        yield 'INFO_FILESYSTEM_USED' => [MetricLabelEnum::INFO_FILESYSTEM_USED, 'gauge', [], []];
        yield 'INFO_OPCACHE_MEMORY_USED' => [MetricLabelEnum::INFO_OPCACHE_MEMORY_USED, 'gauge', [], []];
        yield 'INFO_OPCACHE_MEMORY_WASTED' => [MetricLabelEnum::INFO_OPCACHE_MEMORY_WASTED, 'gauge', [], []];
        yield 'INFO_OPCACHE_CACHED_SCRIPTS' => [MetricLabelEnum::INFO_OPCACHE_CACHED_SCRIPTS, 'gauge', [], []];
        yield 'INFO_OPCACHE_HIT_RATE' => [MetricLabelEnum::INFO_OPCACHE_HIT_RATE, 'gauge', [], []];
        yield 'INFO_FPM_IDLE_PROCESSES' => [MetricLabelEnum::INFO_FPM_IDLE_PROCESSES, 'gauge', [], []];
        yield 'INFO_FPM_ACTIVE_PROCESSES' => [MetricLabelEnum::INFO_FPM_ACTIVE_PROCESSES, 'gauge', [], []];
        yield 'INFO_FPM_TOTAL_PROCESSES' => [MetricLabelEnum::INFO_FPM_TOTAL_PROCESSES, 'gauge', [], []];
        yield 'INFO_FPM_MAX_ACTIVE_PROCESSES' => [MetricLabelEnum::INFO_FPM_MAX_ACTIVE_PROCESSES, 'gauge', [], []];
        yield 'INFO_FPM_LISTEN_QUEUE' => [MetricLabelEnum::INFO_FPM_LISTEN_QUEUE, 'gauge', [], []];
        yield 'INFO_FPM_MAX_LISTEN_QUEUE' => [MetricLabelEnum::INFO_FPM_MAX_LISTEN_QUEUE, 'gauge', [], []];
        yield 'INFO_FPM_LISTEN_QUEUE_SIZE' => [MetricLabelEnum::INFO_FPM_LISTEN_QUEUE_SIZE, 'gauge', [], []];
        yield 'INFO_CACHE_WARMUP_TIME' => [MetricLabelEnum::INFO_CACHE_WARMUP_TIME, 'gauge', [], []];
        yield 'PROFILING_SPAN_DURATION_HISTOGRAM_SECONDS' => [MetricLabelEnum::PROFILING_SPAN_DURATION_HISTOGRAM_SECONDS, 'histogram', ['message:string'], [0.1, 0.5, 1, 3, 5, 10, 15, 30, 60, 180]];
        yield 'MESSAGE_SENT' => [MessengerMetricLabelEnum::MESSAGE_SENT, 'counter', ['transport:string', 'message:string'], []];
        yield 'MESSAGE_HANDLED' => [MessengerMetricLabelEnum::MESSAGE_HANDLED, 'counter', ['transport:string', 'message:string', 'status:enum=handled|failed|retried'], []];
        yield 'MESSAGE_HANDLING_DURATION_HISTOGRAM_SECONDS' => [MessengerMetricLabelEnum::MESSAGE_HANDLING_DURATION_HISTOGRAM_SECONDS, 'histogram', ['transport:string', 'message:string', 'status:enum=handled|failed|retried'], [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10, 30, 60, 180]];
    }

    /**
     * @param list<string> $labels "name:type", plus "=v1|v2" for enum labels
     * @param list<int|float> $buckets
     */
    #[DataProvider('catalogProvider')]
    public function testMetricContract(MetricLabelEnumInterface $metric, string $type, array $labels, array $buckets): void
    {
        self::assertSame($type, $metric->getType()->value);
        self::assertNotSame('', $metric->getDescription());
        self::assertSame($labels, array_map($this->describe(...), $metric->getLabels()));
        self::assertSame($buckets, $metric->getBatches());
    }

    public function testEveryCaseIsPinned(): void
    {
        $pinned = array_map(static fn (array $row): string => self::caseId($row[0]), iterator_to_array(self::catalogProvider(), false));
        $declared = array_map(self::caseId(...), [...MetricLabelEnum::cases(), ...MessengerMetricLabelEnum::cases()]);

        // Case order is not part of the contract.
        sort($pinned);
        sort($declared);
        self::assertSame($declared, $pinned);
    }

    private static function caseId(MetricLabelEnumInterface $case): string
    {
        return $case::class . '::' . $case->name;
    }

    private function describe(Label $label): string
    {
        $description = $label->name . ':' . $label->type->value;

        return $label->enums === [] ? $description : $description . '=' . implode('|', $label->enums);
    }
}
