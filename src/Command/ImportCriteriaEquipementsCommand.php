<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Import\EquipmentImporter;
use App\Service\Import\EquipmentSourceDownloader;
use App\Service\Import\ImportSource;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

#[AsCommand(
    name: 'app:import:criteria:equipements',
    description: 'Import the facility count criteria (shops, health, sport, culture, services) and their density per 1000 inhabitants from resources/geo/equipements-bpe.csv, per unité urbaine',
    help: <<<'HELP'
        Idempotent — safe to re-run. Import the zones and the population first.

        With <comment>--download</comment>, the vendored facility file is refreshed first from INSEE's Mélodi API (about ten minutes: paged requests, throttled).
        In dev, add <comment>--no-debug</comment>: the profiler keeps every response of the download in memory.
        HELP,
)]
#[ImportSource('equipements-bpe.csv')]
final class ImportCriteriaEquipementsCommand extends Command
{
    public function __construct(
        private readonly EquipmentImporter $equipmentImporter,
        private readonly EquipmentSourceDownloader $equipmentSourceDownloader,
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
                $this->equipmentSourceDownloader->download($dir, $io->writeln(...));
            } catch (ExceptionInterface $e) {
                $io->error(\sprintf('Download failed: %s', $e->getMessage()));

                return Command::FAILURE;
            }

            $io->note('Equipment counts refreshed. Review with `git diff resources/geo/` before committing.');
        }

        $count = $this->equipmentImporter->import($dir.'/equipements-bpe.csv');

        $io->success(\sprintf('%d facility values imported (counts and densities, per unité urbaine and year).', $count));

        return Command::SUCCESS;
    }
}
