<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Import\AgePyramidImporter;
use App\Service\Import\AgePyramidSourceDownloader;
use App\Service\Import\ImportSource;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

#[AsCommand(
    name: 'app:import:age-pyramid',
    description: 'Import the population by sex and five-year age bracket of each unité urbaine from resources/geo/pyramide-ages.csv',
    help: <<<'HELP'
        Idempotent — safe to re-run. Import the zones first.

        With <comment>--download</comment>, the vendored file is refreshed first from INSEE's Mélodi API:
        one throttled request per unité urbaine, so expect more than an hour. A failed request is
        retried, but an interrupted download starts over.
        HELP,
)]
#[ImportSource('pyramide-ages.csv')]
final class ImportAgePyramidCommand extends Command
{
    public function __construct(
        private readonly AgePyramidImporter $agePyramidImporter,
        private readonly AgePyramidSourceDownloader $agePyramidSourceDownloader,
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
                $this->agePyramidSourceDownloader->download($dir, $io->writeln(...));
            } catch (ExceptionInterface $e) {
                $io->error(\sprintf('Download failed: %s', $e->getMessage()));

                return Command::FAILURE;
            }

            $io->note('Age pyramids refreshed. Review with `git diff resources/geo/` before committing.');
        }

        $count = $this->agePyramidImporter->import($dir.'/pyramide-ages.csv');

        $io->success(\sprintf('%d age pyramids imported (one per unité urbaine).', $count));

        return Command::SUCCESS;
    }
}
