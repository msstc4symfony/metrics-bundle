<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Presentation\Command;

use MaxShamaev\MetricsBundle\Infrastructure\Entity\Label;
use MaxShamaev\MetricsBundle\Infrastructure\Enum\MetricLabelTypeEnum;
use MaxShamaev\MetricsBundle\Infrastructure\Repository\MetricRepositoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Service\Attribute\Required;

#[AsCommand(name: 'metrics:list', description: 'Get metrics list')]
class GetMetricsListCommand extends Command
{
    private MetricRepositoryInterface $repository;

    #[Required]
    public function setRepository(MetricRepositoryInterface $repository): void
    {
        $this->repository = $repository;
    }

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
