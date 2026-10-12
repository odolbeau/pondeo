<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Import\EducationImporter;
use App\Service\Import\EducationSourceDownloader;
use App\Service\Import\ImportSource;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

#[AsCommand(
    name: 'app:import:criteria:education',
    description: 'Import the share of higher-education graduates from resources/geo/diplomes-recensement.csv, summed per unité urbaine',
    help: <<<'HELP'
        Idempotent — safe to re-run. Import the zones first.

        With <comment>--download</comment>, the vendored file is refreshed first from INSEE's Mélodi API
        (a couple of dozen paged requests, throttled).
        HELP,
)]
#[ImportSource('diplomes-recensement.csv')]
final class ImportCriteriaEducationCommand extends Command
{
    public function __construct(
        private readonly EducationImporter $educationImporter,
        private readonly EducationSourceDownloader $educationSourceDownloader,
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
                $this->educationSourceDownloader->download($dir, $io->writeln(...));
            } catch (ExceptionInterface $e) {
                $io->error(\sprintf('Download failed: %s', $e->getMessage()));

                return Command::FAILURE;
            }

            $io->note('Education figures refreshed. Review with `git diff resources/geo/` before committing.');
        }

        $count = $this->educationImporter->import($dir.'/diplomes-recensement.csv');

        $io->success(\sprintf('%d education share values imported (per unité urbaine and census year).', $count));

        return Command::SUCCESS;
    }
}
