<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Presentation\Command;

use Prometheus\Storage\Adapter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Service\Attribute\Required;

#[AsCommand(name: 'metrics:clear', description: 'Clear metrics storage')]
class ClearMetricsCommand extends Command
{
    private Adapter $storage;

    #[Required]
    public function setStorage(Adapter $storage): void
    {
        $this->storage = $storage;
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $io->writeln('Clearing storage');
        $this->storage->wipeStorage();
        $io->success('The storage was successfully cleared.');

        return Command::SUCCESS;
    }
}
