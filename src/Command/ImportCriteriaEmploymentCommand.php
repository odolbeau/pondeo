<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Import\EmploymentImporter;
use App\Service\Import\EmploymentSourceDownloader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

#[AsCommand(
    name: 'app:import:criteria:employment',
    description: 'Import the active and unemployed populations from resources/geo/emploi-recensement.csv, summed per unité urbaine, and derive the unemployment and activity rates',
    help: <<<'HELP'
        Idempotent — safe to re-run. Import the zones first.

        With <comment>--download</comment>, the vendored file is refreshed first from INSEE's Mélodi API
        (a few dozen paged requests, throttled: expect a couple of minutes).
        HELP,
)]
final class ImportCriteriaEmploymentCommand extends Command
{
    public function __construct(
        private readonly EmploymentImporter $employmentImporter,
        private readonly EmploymentSourceDownloader $employmentSourceDownloader,
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
                $this->employmentSourceDownloader->download($dir, $io->writeln(...));
            } catch (ExceptionInterface $e) {
                $io->error(\sprintf('Download failed: %s', $e->getMessage()));

                return Command::FAILURE;
            }

            $io->note('Employment figures refreshed. Review with `git diff resources/geo/` before committing.');
        }

        $count = $this->employmentImporter->import($dir.'/emploi-recensement.csv');

        $io->success(\sprintf('%d employment rate values imported (per unité urbaine and census year).', $count));

        return Command::SUCCESS;
    }
}
