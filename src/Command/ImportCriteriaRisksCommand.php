<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Import\CsvCriterionImporter;
use App\Service\Import\RiskCriteria;
use App\Service\Import\RiskSourceDownloader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

#[AsCommand(
    name: 'app:import:criteria:risks',
    description: 'Import the natural and technological risk criteria from resources/geo/risques-communes.csv, combined per unité urbaine',
    help: <<<'HELP'
        Idempotent — safe to re-run.

        With <comment>--download</comment>, the vendored file is refreshed first from the Géorisques API
        (about five calls per commune for some 7 500 communes: expect one to two hours).
        An interrupted download is resumable: re-run it and communes already fetched this year are skipped.
        HELP,
)]
final class ImportCriteriaRisksCommand extends Command
{
    public function __construct(
        private readonly CsvCriterionImporter $csvCriterionImporter,
        private readonly RiskSourceDownloader $riskSourceDownloader,
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
                $fetched = $this->riskSourceDownloader->download($dir, $io->writeln(...));
            } catch (ExceptionInterface $e) {
                $io->error(\sprintf('Download failed: %s. Re-run to resume.', $e->getMessage()));

                return Command::FAILURE;
            }

            $io->note(\sprintf('Risks refreshed (%d communes fetched). Review with `git diff resources/geo/` before committing.', $fetched));
        }

        $count = 0;
        foreach (RiskCriteria::specs() as $spec) {
            $count += $this->csvCriterionImporter->import($spec, $dir.'/'.RiskSourceDownloader::FILE);
        }

        $io->success(\sprintf('%d risk values imported (one per criterion and unité urbaine).', $count));

        return Command::SUCCESS;
    }
}
