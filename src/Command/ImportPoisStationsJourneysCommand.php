<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Import\StationJourneyImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:import:pois:stations:journeys',
    description: 'Import the train journeys from each station to Nantes and Paris (resources/geo/trajets-gares.csv)',
    help: <<<'HELP'
        Idempotent upsert — safe to re-run, and works offline. Run <comment>app:import:pois:stations</comment>
        first: each line is attached to its station.
        HELP,
)]
final class ImportPoisStationsJourneysCommand extends Command
{
    public function __construct(private readonly StationJourneyImporter $stationJourneyImporter)
    {
        parent::__construct();
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $result = $this->stationJourneyImporter->import(\dirname(__DIR__, 2).'/resources/geo/trajets-gares.csv');

        $io->success(\sprintf('%d station journeys imported (%d skipped: unknown station or destination).', $result['imported'], $result['skipped']));

        return Command::SUCCESS;
    }
}
