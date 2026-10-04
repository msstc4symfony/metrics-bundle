<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Presentation\Command;

use Msstc4Symfony\MetricsBundle\Infrastructure\Entity\Label;
use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MetricLabelTypeEnum;
use Msstc4Symfony\MetricsBundle\Infrastructure\Repository\MetricRepositoryInterface;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Service\Attribute\Required;

#[AsCommand(name: 'metrics:list', description: 'Get metrics list')]
final class GetMetricsListCommand extends Command
{
    private MetricRepositoryInterface $repository;

    #[Required]
    public function setRepository(MetricRepositoryInterface $repository): void
    {
        $this->repository = $repository;
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $values = [];
        foreach ($this->repository->findAll() as $metric) {
            $values[] = [
                $metric->name->value,
                $metric->type->value,
                $metric->description,
                $this->prepareLabels($metric->labels),
                implode(', ', $metric->batches),
            ];
        }
        $io->table(
            ['Name', 'Type', 'Description', 'Labels', 'Histogram batches'],
            $values,
        );

        return Command::SUCCESS;
    }

    /**
     * @param Label[] $labels
     */
    private function prepareLabels(array $labels): string
    {
        $result = [];
        foreach ($labels as $label) {
            $line = $label->name . "\t" . $label->type->value;
            if ($label->type === MetricLabelTypeEnum::ENUM) {
                $line .= ' [' . implode(', ', $label->enums) . ']';
            }

            if ($label->description !== null) {
                $line .= "\t" . $label->description;
            }

            $result[] = trim($line);
        }

        return implode(PHP_EOL, $result);
    }
}
