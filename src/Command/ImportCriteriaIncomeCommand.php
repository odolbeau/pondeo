<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Import\ImportSource;
use App\Service\Import\IncomeImporter;
use App\Service\Import\IncomeSourceDownloader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

#[AsCommand(
    name: 'app:import:criteria:income',
    description: 'Import the median standard of living and the poverty rate from resources/geo/revenus-filosofi.csv, per unité urbaine',
    help: <<<'HELP'
        Idempotent — safe to re-run. Import the zones first.

        With <comment>--download</comment>, the vendored file is refreshed first from INSEE's Mélodi API
        (a few dozen paged requests, throttled).
        HELP,
)]
#[ImportSource('revenus-filosofi.csv')]
final class ImportCriteriaIncomeCommand extends Command
{
    public function __construct(
        private readonly IncomeImporter $incomeImporter,
        private readonly IncomeSourceDownloader $incomeSourceDownloader,
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
                $this->incomeSourceDownloader->download($dir, $io->writeln(...));
            } catch (ExceptionInterface $e) {
                $io->error(\sprintf('Download failed: %s', $e->getMessage()));

                return Command::FAILURE;
            }

            $io->note('Income figures refreshed. Review with `git diff resources/geo/` before committing.');
        }

        $count = $this->incomeImporter->import($dir.'/revenus-filosofi.csv');

        $io->success(\sprintf('%d income values imported (per unité urbaine).', $count));

        return Command::SUCCESS;
    }
}
