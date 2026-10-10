<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\CriterionValueType;
use App\Service\Import\CriterionCsvSpec;
use App\Service\Import\CsvCriterionImporter;
use App\Service\Import\PopulationSourceDownloader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

#[AsCommand(
    name: 'app:import:criteria:population',
    description: 'Import the population criterion (all years) from resources/geo/population-historique.csv, summed per unité urbaine',
    help: <<<'HELP'
        Idempotent — safe to re-run.

        With <comment>--download</comment>, the vendored file is refreshed first from INSEE's Mélodi API
        (about 70 paged requests, throttled: expect a few minutes).
        HELP,
)]
final class ImportCriteriaPopulationCommand extends Command
{
    public function __construct(
        private readonly CsvCriterionImporter $csvCriterionImporter,
        private readonly PopulationSourceDownloader $populationSourceDownloader,
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
        );

        $count = $this->csvCriterionImporter->import($spec, $dir.'/population-historique.csv');

        $io->success(\sprintf('%d population values imported (one per unité urbaine and year).', $count));

        return Command::SUCCESS;
    }
}
