<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Test\Unit\Presentation\Command;

use MaxShamaev\MetricsBundle\Infrastructure\Entity\Metric;
use MaxShamaev\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;
use MaxShamaev\MetricsBundle\Infrastructure\Repository\MetricRepositoryInterface;
use MaxShamaev\MetricsBundle\Presentation\Command\GetMetricsListCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class GetMetricsListCommandTest extends TestCase
{
    public function testInvoke(): void
    {
        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('findAll')
            ->willReturn(
                [
                    new Metric(
                        MetricLabelEnum::HTTP_REQUEST,
                        MetricLabelEnum::HTTP_REQUEST->getType(),
                        MetricLabelEnum::HTTP_REQUEST->getDescription(),
                        MetricLabelEnum::HTTP_REQUEST->getLabels(),
                        MetricLabelEnum::HTTP_REQUEST->getBatches(),
                    ),
                ],
            )
        ;

        $command = new GetMetricsListCommand();
        $command->setRepository($repository);

        $commandTester = new CommandTester($command);

        $exitCode = $commandTester->execute([]);

        self::assertSame(0, $exitCode);
        self::assertSame(
            <<<TXT
 -------------- --------- ----------------------------- --------------------------------------------------------------- ------------------- 
  Name           Type      Description                   Labels                                                          Histogram batches  
 -------------- --------- ----------------------------- --------------------------------------------------------------- ------------------- 
  http_request   counter   Incoming HTTP request count   method	enum [GET, POST, DELETE, PUT, PATCH, HEAD]	HTTP method                      
                                                         route	string	Route name or URL path                                                
 -------------- --------- ----------------------------- --------------------------------------------------------------- ------------------- 


TXT,
            $commandTester->getDisplay(),
        );
    }
}
