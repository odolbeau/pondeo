<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Import\CommercesImporter;
use App\Service\Import\CommercesSourceDownloader;
use App\Service\Import\ImportSource;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

#[AsCommand(
    name: 'app:import:criteria:commerces',
    description: 'Import the shop count criteria and their density per 1000 inhabitants from resources/geo/commerces-bpe.csv, per unité urbaine',
    help: <<<'HELP'
        Idempotent — safe to re-run. Import the zones and the population first.

        With <comment>--download</comment>, the vendored file is refreshed first from INSEE's Mélodi API
        (a dozen paged requests, throttled).
        HELP,
)]
#[ImportSource('commerces-bpe.csv')]
final class ImportCriteriaCommercesCommand extends Command
{
    public function __construct(
        private readonly CommercesImporter $commercesImporter,
        private readonly CommercesSourceDownloader $commercesSourceDownloader,
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
                $this->commercesSourceDownloader->download($dir, $io->writeln(...));
            } catch (ExceptionInterface $e) {
                $io->error(\sprintf('Download failed: %s', $e->getMessage()));

                return Command::FAILURE;
            }

            $io->note('Shop counts refreshed. Review with `git diff resources/geo/` before committing.');
        }

        $count = $this->commercesImporter->import($dir.'/commerces-bpe.csv');

        $io->success(\sprintf('%d shop values imported (counts and densities, per unité urbaine and year).', $count));

        return Command::SUCCESS;
    }
}
