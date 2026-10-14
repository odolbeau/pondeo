<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Import\ImportSource;
use App\Service\Import\TransitStopImporter;
use App\Service\Import\TransitStopSourceDownloader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

#[AsCommand(
    name: 'app:import:pois:transit-stops',
    description: 'Import the tram and metro stations (resources/geo/arrets-tram-metro.csv) as POIs',
    help: <<<'HELP'
        Idempotent upsert — safe to re-run. Run <comment>app:import:zones</comment> first so each station is
        attached to its unité urbaine.

        With <comment>--download</comment>, the vendored file is refreshed first from the "équipement de
        transport" layer of IGN's BD TOPO (Géoplateforme, Licence Ouverte).
        HELP,
)]
#[ImportSource('arrets-tram-metro.csv', 'communes.geojson')]
final class ImportPoisTransitStopsCommand extends Command
{
    public function __construct(
        private readonly TransitStopImporter $transitStopImporter,
        private readonly TransitStopSourceDownloader $transitStopSourceDownloader,
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
                $this->transitStopSourceDownloader->download($dir, $io->writeln(...));
            } catch (ExceptionInterface $e) {
                $io->error(\sprintf('Download failed: %s', $e->getMessage()));

                return Command::FAILURE;
            }

            $io->note('Reference file refreshed. Review with `git diff resources/geo/` before committing.');
        }

        $count = $this->transitStopImporter->import($dir.'/arrets-tram-metro.csv');

        $io->success(\sprintf('%d tram and metro stations imported.', $count));

        return Command::SUCCESS;
    }
}
