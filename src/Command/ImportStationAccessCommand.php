<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Import\StationAccessImporter;
use App\Service\Import\StationAccessSourceDownloader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:import:station-access',
    description: 'Import the closest railway stations of each unité urbaine, with their car route, from resources/geo/gares-acces-voiture.csv',
    help: <<<'HELP'
        Idempotent — safe to re-run, and works offline. Import the zones and the stations first.

        With <comment>--download</comment>, the vendored file is first recomputed from the IGN routing service
        (about 5 000 throttled requests: expect around 45 minutes). Only needed when the stations or the
        urban units change.
        HELP,
)]
final class ImportStationAccessCommand extends Command
{
    public function __construct(
        private readonly StationAccessImporter $stationAccessImporter,
        private readonly StationAccessSourceDownloader $stationAccessSourceDownloader,
    ) {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Recompute the vendored source file before importing')]
        bool $download = false,
    ): int {
        $dir = \dirname(__DIR__, 2).'/resources/geo';

        if ($download) {
            $written = $this->stationAccessSourceDownloader->download($dir, $io->writeln(...));

            $io->note(\sprintf('Routes computed for %d units. Review with `git diff resources/geo/` before committing.', $written));
        }

        $count = $this->stationAccessImporter->import($dir.'/'.StationAccessSourceDownloader::FILE);

        $io->success(\sprintf('%d unité urbaine / station links imported.', $count));

        return Command::SUCCESS;
    }
}
