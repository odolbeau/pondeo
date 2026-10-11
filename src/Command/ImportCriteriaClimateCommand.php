<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Import\ClimateImporter;
use App\Service\Import\ClimateSourceDownloader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

#[AsCommand(
    name: 'app:import:criteria:climate',
    description: 'Import the 1991-2020 climate normals from resources/geo/climat-normales.csv, per unité urbaine',
    help: <<<'HELP'
        Idempotent — safe to re-run. Import the zones first.

        With <comment>--download</comment>, the vendored file is completed from the Open-Meteo
        historical weather API. Units already in the file are skipped, so a run
        interrupted by the API quotas (about 600 calls per minute, 5 000 per hour and
        10 000 per day, a 30-year request weighing about 600 calls per location) is
        simply re-run later. Delete the file to recompute everything.
        HELP,
)]
final class ImportCriteriaClimateCommand extends Command
{
    public function __construct(
        private readonly ClimateImporter $climateImporter,
        private readonly ClimateSourceDownloader $climateSourceDownloader,
    ) {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Complete the vendored source file before importing')]
        bool $download = false,
    ): int {
        $dir = \dirname(__DIR__, 2).'/resources/geo';

        if ($download) {
            try {
                $written = $this->climateSourceDownloader->download($dir, $io->writeln(...));
            } catch (ExceptionInterface $e) {
                $io->error(\sprintf('Download stopped: %s. Re-run later to resume.', $e->getMessage()));

                return Command::FAILURE;
            }

            $io->note(\sprintf('%d units added. Review with `git diff resources/geo/` before committing.', $written));
        }

        $file = $dir.'/'.ClimateSourceDownloader::FILE;
        if (!is_file($file)) {
            $io->warning(\sprintf('%s is missing: nothing imported. Build it with --download.', ClimateSourceDownloader::FILE));

            return Command::SUCCESS;
        }

        $count = $this->climateImporter->import($file);

        $io->success(\sprintf('%d climate values imported (per unité urbaine).', $count));

        return Command::SUCCESS;
    }
}
