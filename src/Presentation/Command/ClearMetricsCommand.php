<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Presentation\Command;

use Override;
use Prometheus\Storage\Adapter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Service\Attribute\Required;

#[AsCommand(name: 'metrics:clear', description: 'Clear metrics storage')]
final class ClearMetricsCommand extends Command
{
    private Adapter $storage;

    #[Required]
    public function setStorage(Adapter $storage): void
    {
        $this->storage = $storage;
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->writeln('Clearing storage');

        $this->storage->wipeStorage();
        $io->success('The storage was successfully cleared.');

        return Command::SUCCESS;
    }
}
