<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Import\ImportSource;
use App\Service\Import\PoiCsvImporter;
use App\Service\Import\PollutedSiteSourceDownloader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

#[AsCommand(
    name: 'app:import:pois:polluted-sites',
    description: 'Import the polluted soil sites (resources/geo/sites-pollues.csv) as POIs',
    help: <<<'HELP'
        Idempotent upsert — safe to re-run. Run <comment>app:import:zones</comment> first so each POI is
        attached to its unité urbaine.

        With <comment>--download</comment>, the vendored file is refreshed first from Géorisques' "Secteurs d'information sur les sols" (BRGM).
        HELP,
)]
#[ImportSource('sites-pollues.csv', 'communes.geojson')]
final class ImportPoisPollutedSitesCommand extends Command
{
    public function __construct(
        private readonly PoiCsvImporter $poiCsvImporter,
        private readonly PollutedSiteSourceDownloader $sourceDownloader,
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
                $this->sourceDownloader->download($dir, $io->writeln(...));
            } catch (ExceptionInterface $e) {
                $io->error(\sprintf('Download failed: %s', $e->getMessage()));

                return Command::FAILURE;
            }

            $io->note('Reference file refreshed. Review with `git diff resources/geo/` before committing.');
        }

        $count = $this->poiCsvImporter->import($dir.'/sites-pollues.csv', 'polluted_soil_site');

        $io->success(\sprintf('%d polluted soil sites imported.', $count));

        return Command::SUCCESS;
    }
}
