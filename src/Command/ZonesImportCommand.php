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
    description: 'Import France métropolitaine administrative zones (régions/départements/EPCI/communes)',
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
        // The communes GeoJSON is ~18 MB; json_decode-ing it plus tracking
        // ~35k entities through the ORM comfortably exceeds the project's
        // default 128M CLI memory_limit.
        ini_set('memory_limit', '1G');

        $io = new SymfonyStyle($input, $output);
        $dir = \dirname(__DIR__, 2).'/resources/geo';

        $io->writeln('Importing régions...');
        $io->writeln(\sprintf('  %d régions', $this->zoneImporter->importRegions($dir.'/regions.geojson')));

        $io->writeln('Mapping départements → régions from the commune/EPCI CSV...');

        $regionCodeByDepartementCode = $this->zoneImporter->buildDepartementRegionMap($dir.'/communes-epci.csv');

        $io->writeln('Importing départements...');
        $io->writeln(\sprintf('  %d départements', $this->zoneImporter->importDepartements($dir.'/departements.geojson', $regionCodeByDepartementCode)));

        $io->writeln('Importing communes (this is the slow step)...');
        $io->writeln(\sprintf('  %d communes', $this->zoneImporter->importCommunes($dir.'/communes.geojson')));

        $io->writeln('Linking communes to their EPCI...');
        $io->writeln(\sprintf('  %d communes linked', $this->zoneImporter->importEpciMembership($dir.'/communes-epci.csv')));

        $io->writeln('Computing EPCI boundaries (ST_Union of member communes)...');

        $this->zoneImporter->computeEpciBoundaries();

        $io->success('Zones imported.');

        return Command::SUCCESS;
    }
}
