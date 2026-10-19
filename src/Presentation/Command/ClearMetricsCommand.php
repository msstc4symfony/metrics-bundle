<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Presentation\Command;

use Msstc4Symfony\MetricsBundle\Application\Port\MetricStorageException;
use Msstc4Symfony\MetricsBundle\Application\Port\MetricStorageInterface;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * @internal
 */
#[AsCommand(name: 'metrics:clear', description: 'Clear metrics storage')]
final class ClearMetricsCommand extends Command
{
    private MetricStorageInterface $storage;

    #[Required]
    public function setStorage(MetricStorageInterface $storage): void
    {
        $this->storage = $storage;
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->writeln('Clearing storage');

        try {
            $this->storage->clear();
        } catch (MetricStorageException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->success('The storage was successfully cleared.');

        return Command::SUCCESS;
    }
}
