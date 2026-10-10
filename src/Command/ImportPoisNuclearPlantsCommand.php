<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Import\NuclearPlantImporter;
use App\Service\Import\NuclearPlantSourceDownloader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

#[AsCommand(
    name: 'app:import:pois:nuclear-plants',
    description: 'Import the nuclear power plants (resources/geo/centrales-nucleaires.csv) as POIs',
    help: <<<'HELP'
        Idempotent upsert — safe to re-run. Run <comment>app:import:zones</comment> first so each plant is
        attached to its unité urbaine.

        With <comment>--download</comment>, the vendored file is refreshed first from EDF's "Centrales de
        production nucléaire" dataset (opendata.edf.fr, Licence Ouverte).
        HELP,
)]
final class ImportPoisNuclearPlantsCommand extends Command
{
    public function __construct(
        private readonly NuclearPlantImporter $nuclearPlantImporter,
        private readonly NuclearPlantSourceDownloader $nuclearPlantSourceDownloader,
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
                $this->nuclearPlantSourceDownloader->download($dir, $io->writeln(...));
            } catch (ExceptionInterface $e) {
                $io->error(\sprintf('Download failed: %s', $e->getMessage()));

                return Command::FAILURE;
            }

            $io->note('Reference file refreshed. Review with `git diff resources/geo/` before committing.');
        }

        $count = $this->nuclearPlantImporter->import($dir.'/centrales-nucleaires.csv');

        $io->success(\sprintf('%d nuclear power plants imported.', $count));

        return Command::SUCCESS;
    }
}
