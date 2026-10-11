<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Import\ClimateProjectionImporter;
use App\Service\Import\ClimateProjectionSourceDownloader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

#[AsCommand(
    name: 'app:import:criteria:climate-projections',
    description: 'Import the climate projections towards 2050 from resources/geo/climat-projections.csv, per unité urbaine',
    help: <<<'HELP'
        Idempotent — safe to re-run, and works offline. Import the zones first.

        With <comment>--download</comment>, the vendored file is first recomputed from the Open-Meteo Climate API.
        Every answer is archived outside the repository (OPENMETEO_ARCHIVE_DIR), so the command resumes where it
        stopped and the file can be derived again offline. It is only written, and imported, once every cell is
        archived. Set OPENMETEO_API_KEY to use the paid API; the free quota is far smaller than the run needs.
        HELP,
)]
final class ImportCriteriaClimateProjectionsCommand extends Command
{
    public function __construct(
        private readonly ClimateProjectionImporter $climateProjectionImporter,
        private readonly ClimateProjectionSourceDownloader $climateProjectionSourceDownloader,
    ) {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Recompute the vendored source file before importing')]
        bool $download = false,
    ): int {
        $dir = \dirname(__DIR__, 2).'/resources/geo';

        if ($download) {
            try {
                $result = $this->climateProjectionSourceDownloader->download($dir, $io->writeln(...));
            } catch (ExceptionInterface $e) {
                $io->error(\sprintf('Download failed: %s', $e->getMessage()));

                return Command::FAILURE;
            }

            $io->writeln(\sprintf('About %d API calls spent in this run.', $result['calls']));
            if (!$result['written']) {
                $io->warning(\sprintf('Stopped at %d/%d cells: %s', $result['done'], $result['total'], $result['message'] ?? 'run again to resume.'));

                return Command::FAILURE;
            }

            $io->note('Projections computed. Review with `git diff resources/geo/` before committing.');
        }

        $count = $this->climateProjectionImporter->import($dir.'/'.ClimateProjectionSourceDownloader::FILE);

        $io->success(\sprintf('%d climate projection values imported (per unité urbaine).', $count));

        return Command::SUCCESS;
    }
}
