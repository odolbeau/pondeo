<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Import\PopulationImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:criteria:population:import',
    description: 'Import the population criterion (all years) from resources/geo/population-historique.csv',
    help: 'Idempotent — safe to re-run. See <comment>app:criteria:population:download</comment> to refresh the source file first.',
)]
final class CriteriaPopulationImportCommand extends Command
{
    public function __construct(private readonly PopulationImporter $populationImporter)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // ~627k source rows plus the preloaded commune map comfortably
        // exceed the project's default 128M CLI memory_limit.
        ini_set('memory_limit', '1G');

        $io = new SymfonyStyle($input, $output);
        $csvPath = \dirname(__DIR__, 2).'/resources/geo/population-historique.csv';

        $count = $this->populationImporter->importPopulation($csvPath);
        $io->success(\sprintf('%d population values imported.', $count));

        return Command::SUCCESS;
    }
}
