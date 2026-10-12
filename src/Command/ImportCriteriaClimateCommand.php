<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Import\ClimateImporter;
use App\Service\Import\ClimateSourceDownloader;
use App\Service\Import\ImportSource;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:import:criteria:climate',
    description: 'Import the 1991-2020 climate normals from resources/geo/climat-normales.csv, per unité urbaine',
    help: <<<'HELP'
        Idempotent — safe to re-run. Import the zones first.

        With <comment>--download</comment>, the Open-Meteo historical weather API is called for the
        cells not archived yet (a 30-year request weighs about 1 000 calls per location),
        then the vendored file is rebuilt from the archive kept outside the repository
        (OPENMETEO_ARCHIVE_DIR). Without OPENMETEO_API_KEY the free API is used, with its
        quotas; a run stopped by them is simply re-run later. It never spends more than
        <comment>--max-calls</comment> in total, ledger included.
        HELP,
)]
#[ImportSource('climat-normales.csv')]
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
        #[Option(description: 'Call budget of the download, ledger included (0 for none)')]
        int $maxCalls = 2_400_000,
    ): int {
        $dir = \dirname(__DIR__, 2).'/resources/geo';

        if ($download) {
            try {
                $written = $this->climateSourceDownloader->download($dir, $io->writeln(...), $maxCalls);
            } catch (\RuntimeException $e) {
                $io->error(\sprintf('Download stopped: %s. Re-run later to resume.', $e->getMessage()));

                return Command::FAILURE;
            }

            $io->note(\sprintf('%d units written. Review with `git diff resources/geo/` before committing.', $written));
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
