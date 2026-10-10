<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Import\ZoneImporter;
use App\Service\Import\ZoneSourceDownloader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

#[AsCommand(
    name: 'app:import:zones',
    description: 'Import the unités urbaines of France métropolitaine and their communes',
    help: <<<'HELP'
        Idempotent upsert — safe to re-run.

        With <comment>--download</comment>, the vendored source files under resources/geo/ are refreshed first.
        Only the communes belonging to an unité urbaine, in France métropolitaine, are kept (the sources
        also list the ~27 000 communes outside any unité urbaine, and overseas territories).
        HELP,
)]
final class ImportZonesCommand extends Command
{
    public function __construct(
        private readonly ZoneImporter $zoneImporter,
        private readonly ZoneSourceDownloader $zoneSourceDownloader,
    ) {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Refresh the vendored source files before importing')]
        bool $download = false,
    ): int {
        $dir = \dirname(__DIR__, 2).'/resources/geo';

        if ($download) {
            try {
                $this->zoneSourceDownloader->download($dir, $io->writeln(...));
            } catch (ExceptionInterface $e) {
                $io->error(\sprintf('Download failed: %s', $e->getMessage()));

                return Command::FAILURE;
            }

            $io->note('Reference files refreshed. Review with `git diff resources/geo/` before committing.');
        }

        $io->writeln('Importing unités urbaines and their communes...');
        $counts = $this->zoneImporter->importUnitesUrbaines($dir.'/communes-unites-urbaines.csv');
        $io->writeln(\sprintf('  %d unités urbaines, %d communes', $counts['unitesUrbaines'], $counts['communes']));

        $io->writeln("Importing aires d'attraction...");
        $io->writeln(\sprintf('  %d unités urbaines categorised', $this->zoneImporter->importAttractionAreas($dir.'/communes-unites-urbaines.csv', $dir.'/communes-aires-attraction.csv')));

        $io->writeln('Importing commune boundaries...');
        $io->writeln(\sprintf('  %d boundaries', $this->zoneImporter->importCommuneBoundaries($dir.'/communes.geojson')));

        $io->writeln('Computing unité urbaine boundaries (ST_Union of member communes)...');

        $this->zoneImporter->computeUniteUrbaineBoundaries();

        $io->success('Unités urbaines imported.');

        return Command::SUCCESS;
    }
}
