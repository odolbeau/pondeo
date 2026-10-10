<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Import\ZoneImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:zones:import',
    description: 'Import the unités urbaines of France métropolitaine and their communes',
    help: 'Idempotent upsert — safe to re-run. See <comment>app:zones:download</comment> to refresh the source files first.',
)]
final class ZonesImportCommand extends Command
{
    public function __construct(private readonly ZoneImporter $zoneImporter)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dir = \dirname(__DIR__, 2).'/resources/geo';

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
