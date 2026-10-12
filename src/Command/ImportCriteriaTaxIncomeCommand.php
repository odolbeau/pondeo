<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Import\ImportSource;
use App\Service\Import\TaxIncomeImporter;
use App\Service\Import\TaxIncomeSourceDownloader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

#[AsCommand(
    name: 'app:import:criteria:tax-income',
    description: 'Import the mean reference taxable income and the share of tax households by income bracket from resources/geo/revenus-fiscaux.csv, summed per unité urbaine',
    help: <<<'HELP'
        Idempotent — safe to re-run. Import the zones first.

        With <comment>--download</comment>, the vendored file is refreshed first from the DGFiP's IRCOM on
        data.gouv.fr (one 18 MB archive: the latest income year published is kept).
        HELP,
)]
#[ImportSource('revenus-fiscaux.csv')]
final class ImportCriteriaTaxIncomeCommand extends Command
{
    public function __construct(
        private readonly TaxIncomeImporter $taxIncomeImporter,
        private readonly TaxIncomeSourceDownloader $taxIncomeSourceDownloader,
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
                $this->taxIncomeSourceDownloader->download($dir, $io->writeln(...));
            } catch (ExceptionInterface $e) {
                $io->error(\sprintf('Download failed: %s', $e->getMessage()));

                return Command::FAILURE;
            }

            $io->note('Tax income refreshed. Review with `git diff resources/geo/` before committing.');
        }

        $count = $this->taxIncomeImporter->import($dir.'/revenus-fiscaux.csv');

        $io->success(\sprintf('%d tax income values imported (per unité urbaine).', $count));

        return Command::SUCCESS;
    }
}
