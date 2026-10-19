<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\Application\Collector;

use Msstc4Symfony\MetricsBundle\Test\Support\RecordingMetricRecorder;
use Override;
use PHPUnit\Framework\TestCase;

abstract class RecorderTestCase extends TestCase
{
    protected RecordingMetricRecorder $recorder;

    #[Override]
    protected function setUp(): void
    {
        $this->recorder = new RecordingMetricRecorder();
    }
}
