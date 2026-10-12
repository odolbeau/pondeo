<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\CriterionValueType;
use App\Service\Import\CriterionCsvSpec;
use App\Service\Import\CsvCriterionImporter;
use App\Service\Import\ImportSource;
use App\Service\Import\PopulationSourceDownloader;
use App\Service\Import\PopulationTrendDeriver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

#[AsCommand(
    name: 'app:import:criteria:population',
    description: 'Import the population criterion (all years) from resources/geo/population-historique.csv, summed per unité urbaine, and derive growth and density from it',
    help: <<<'HELP'
        Idempotent — safe to re-run.

        With <comment>--download</comment>, the vendored file is refreshed first from INSEE's Mélodi API
        (about 70 paged requests, throttled: expect a few minutes).
        HELP,
)]
#[ImportSource('population-historique.csv')]
final class ImportCriteriaPopulationCommand extends Command
{
    public function __construct(
        private readonly CsvCriterionImporter $csvCriterionImporter,
        private readonly PopulationSourceDownloader $populationSourceDownloader,
        private readonly PopulationTrendDeriver $populationTrendDeriver,
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
                $this->populationSourceDownloader->download($dir, $io->writeln(...));
            } catch (ExceptionInterface $e) {
                $io->error(\sprintf('Download failed: %s', $e->getMessage()));

                return Command::FAILURE;
            }

            $io->note('Population history refreshed. Review with `git diff resources/geo/` before committing.');
        }

        $spec = new CriterionCsvSpec(
            code: 'population',
            label: 'Population',
            valueType: CriterionValueType::Integer,
            unit: 'hab.',
            communeCodeColumn: 'code_insee',
            periodColumn: 'year',
            valueColumn: 'population',
            groupLabel: 'Démographie',
            position: 0,
        );

        $count = $this->csvCriterionImporter->import($spec, $dir.'/population-historique.csv');

        $trendCount = $this->populationTrendDeriver->derive();

        $io->success(\sprintf('%d population values imported (one per unité urbaine and year), %d growth and density values derived.', $count, $trendCount));

        return Command::SUCCESS;
    }
}
