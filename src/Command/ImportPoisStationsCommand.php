<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Import\ImportSource;
use App\Service\Import\StationImporter;
use App\Service\Import\StationSourceDownloader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

#[AsCommand(
    name: 'app:import:pois:stations',
    description: 'Import the railway stations (resources/geo/gares-voyageurs.csv) as POIs',
    help: <<<'HELP'
        Idempotent upsert — safe to re-run. Run <comment>app:import:zones</comment> first so each station is
        attached to its unité urbaine.

        With <comment>--download</comment>, the vendored file is refreshed first from the "Gares de voyageurs"
        dataset (SNCF Gares & Connexions, data.gouv.fr, ODbL).
        HELP,
)]
#[ImportSource('gares-voyageurs.csv', 'communes.geojson')]
final class ImportPoisStationsCommand extends Command
{
    public function __construct(
        private readonly StationImporter $stationImporter,
        private readonly StationSourceDownloader $stationSourceDownloader,
    ) {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Refresh the vendored source file before importing')]
        bool $download = false,
    ): int {
        $dir = \dirname(__DIR__, 2).'/resources/geo';

        if ($download) {
            try {
                $this->stationSourceDownloader->download($dir, $io->writeln(...));
            } catch (ExceptionInterface $e) {
                $io->error(\sprintf('Download failed: %s', $e->getMessage()));

                return Command::FAILURE;
            }

            $io->note('Reference file refreshed. Review with `git diff resources/geo/` before committing.');
        }

        $count = $this->stationImporter->import($dir.'/gares-voyageurs.csv');

        $io->success(\sprintf('%d railway stations imported.', $count));

        return Command::SUCCESS;
    }
}
