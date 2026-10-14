<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Import\ImportSource;
use App\Service\Import\SevesoSiteImporter;
use App\Service\Import\SevesoSiteSourceDownloader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

#[AsCommand(
    name: 'app:import:pois:seveso-sites',
    description: 'Import the Seveso sites (resources/geo/sites-seveso.csv) as POIs',
    help: <<<'HELP'
        Idempotent upsert — safe to re-run. Run <comment>app:import:zones</comment> first so each site is
        attached to its unité urbaine.

        With <comment>--download</comment>, the vendored file is refreshed first from Géorisques' "Établissements
        Seveso - France métropolitaine" layer (BRGM, data.gouv.fr).
        HELP,
)]
#[ImportSource('sites-seveso.csv', 'communes.geojson')]
final class ImportPoisSevesoSitesCommand extends Command
{
    public function __construct(
        private readonly SevesoSiteImporter $sevesoSiteImporter,
        private readonly SevesoSiteSourceDownloader $sevesoSiteSourceDownloader,
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
                $this->sevesoSiteSourceDownloader->download($dir, $io->writeln(...));
            } catch (ExceptionInterface $e) {
                $io->error(\sprintf('Download failed: %s', $e->getMessage()));

                return Command::FAILURE;
            }

            $io->note('Reference file refreshed. Review with `git diff resources/geo/` before committing.');
        }

        $count = $this->sevesoSiteImporter->import($dir.'/sites-seveso.csv');

        $io->success(\sprintf('%d Seveso sites imported.', $count));

        return Command::SUCCESS;
    }
}
