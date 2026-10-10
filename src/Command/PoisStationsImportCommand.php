<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Import\StationImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:pois:stations:import',
    description: 'Import the railway stations (resources/geo/gares-voyageurs.csv) as POIs',
    help: 'Idempotent upsert — safe to re-run. Run <comment>app:zones:import</comment> first so each station is attached to its unité urbaine. See <comment>app:pois:stations:download</comment> to refresh the source file first.',
)]
final class PoisStationsImportCommand extends Command
{
    public function __construct(private readonly StationImporter $stationImporter)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $count = $this->stationImporter->import(\dirname(__DIR__, 2).'/resources/geo/gares-voyageurs.csv');

        $io->success(\sprintf('%d railway stations imported.', $count));

        return Command::SUCCESS;
    }
}
