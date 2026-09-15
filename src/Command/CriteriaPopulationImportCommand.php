<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\CriterionValueType;
use App\Enum\ZoneType;
use App\Service\Import\CriterionAggregator;
use App\Service\Import\CriterionCsvSpec;
use App\Service\Import\CsvCriterionImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:criteria:population:import',
    description: 'Import the population criterion (all years) from resources/geo/population-historique.csv, then aggregate it up to EPCI/département/région',
    help: 'Idempotent — safe to re-run. See <comment>app:criteria:population:download</comment> to refresh the source file first.',
)]
final class CriteriaPopulationImportCommand extends Command
{
    public function __construct(
        private readonly CsvCriterionImporter $csvCriterionImporter,
        private readonly CriterionAggregator $criterionAggregator,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // ~627k source rows plus the preloaded commune map comfortably
        // exceed the project's default 128M CLI memory_limit.
        ini_set('memory_limit', '1G');

        $io = new SymfonyStyle($input, $output);
        $csvPath = \dirname(__DIR__, 2).'/resources/geo/population-historique.csv';

        $spec = new CriterionCsvSpec(
            code: 'population',
            label: 'Population',
            valueType: CriterionValueType::Integer,
            zoneType: ZoneType::Commune,
            unit: 'hab.',
            zoneCodeColumn: 'code_insee',
            periodColumn: 'year',
            valueColumn: 'population',
        );

        $count = $this->csvCriterionImporter->import($spec, $csvPath);
        $io->writeln(\sprintf('%d commune-level population values imported.', $count));

        foreach ([ZoneType::Epci, ZoneType::Departement, ZoneType::Region] as $zoneType) {
            $aggregated = $this->criterionAggregator->aggregate('population', $zoneType);
            $io->writeln(\sprintf('%s: %d values aggregated.', $zoneType->value, $aggregated));
        }

        $io->success('Population imported and aggregated to EPCI/département/région.');

        return Command::SUCCESS;
    }
}
