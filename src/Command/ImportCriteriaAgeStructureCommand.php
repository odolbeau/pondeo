<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Import\AgeStructureImporter;
use App\Service\Import\AgeStructureSourceDownloader;
use App\Service\Import\ImportSource;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

#[AsCommand(
    name: 'app:import:criteria:age-structure',
    description: 'Import the share of seven age brackets from resources/geo/structure-age-recensement.csv, summed per unité urbaine',
    help: <<<'HELP'
        Idempotent — safe to re-run. Import the zones first.

        With <comment>--download</comment>, the vendored file is refreshed first from INSEE's Mélodi API
        (a few dozen paged requests, throttled).
        HELP,
)]
#[ImportSource('structure-age-recensement.csv')]
final class ImportCriteriaAgeStructureCommand extends Command
{
    public function __construct(
        private readonly AgeStructureImporter $ageStructureImporter,
        private readonly AgeStructureSourceDownloader $ageStructureSourceDownloader,
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
                $this->ageStructureSourceDownloader->download($dir, $io->writeln(...));
            } catch (ExceptionInterface $e) {
                $io->error(\sprintf('Download failed: %s', $e->getMessage()));

                return Command::FAILURE;
            }

            $io->note('Age structure refreshed. Review with `git diff resources/geo/` before committing.');
        }

        $count = $this->ageStructureImporter->import($dir.'/structure-age-recensement.csv');

        $io->success(\sprintf('%d age bracket share values imported (per unité urbaine and census year).', $count));

        return Command::SUCCESS;
    }
}
