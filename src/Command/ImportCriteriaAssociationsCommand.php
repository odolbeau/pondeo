<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Import\AssociationsImporter;
use App\Service\Import\AssociationsSourceDownloader;
use App\Service\Import\ImportSource;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

#[AsCommand(
    name: 'app:import:criteria:associations',
    description: 'Import the association counts and their density per 1000 inhabitants from resources/geo/associations-rna.csv, per unité urbaine',
    help: <<<'HELP'
        Idempotent — safe to re-run. Import the zones and the population first.

        With <comment>--download</comment>, the vendored file is refreshed first from the latest monthly
        extract of the Répertoire national des associations (a download of about 400 MB, counted on the fly).
        HELP,
)]
#[ImportSource('associations-rna.csv')]
final class ImportCriteriaAssociationsCommand extends Command
{
    public function __construct(
        private readonly AssociationsImporter $associationsImporter,
        private readonly AssociationsSourceDownloader $associationsSourceDownloader,
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
                $this->associationsSourceDownloader->download($dir, $io->writeln(...));
            } catch (ExceptionInterface $e) {
                $io->error(\sprintf('Download failed: %s', $e->getMessage()));

                return Command::FAILURE;
            }

            $io->note('Association counts refreshed. Review with `git diff resources/geo/` before committing.');
        }

        $count = $this->associationsImporter->import($dir.'/associations-rna.csv');

        $io->success(\sprintf('%d association values imported (counts and densities, per unité urbaine and year).', $count));

        return Command::SUCCESS;
    }
}
