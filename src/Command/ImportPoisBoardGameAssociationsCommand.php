<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Import\BoardGameAssociationImporter;
use App\Service\Import\BoardGameAssociationSourceDownloader;
use App\Service\Import\ImportSource;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

#[AsCommand(
    name: 'app:import:pois:board-game-associations',
    description: 'Import the board game associations (resources/geo/associations-jeux.csv) as POIs',
    help: <<<'HELP'
        Idempotent upsert — safe to re-run. Run <comment>app:import:zones</comment> first so each association
        is attached to its unité urbaine.

        With <comment>--download</comment>, the vendored file is refreshed first from Subverti's directory of
        board game structures (subverti.com), used with their permission.
        HELP,
)]
#[ImportSource('associations-jeux.csv', 'communes.geojson')]
final class ImportPoisBoardGameAssociationsCommand extends Command
{
    public function __construct(
        private readonly BoardGameAssociationImporter $importer,
        private readonly BoardGameAssociationSourceDownloader $downloader,
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
                $this->downloader->download($dir, $io->writeln(...));
            } catch (ExceptionInterface $e) {
                $io->error(\sprintf('Download failed: %s', $e->getMessage()));

                return Command::FAILURE;
            }

            $io->note('Reference file refreshed. Review with `git diff resources/geo/` before committing.');
        }

        $count = $this->importer->import($dir.'/associations-jeux.csv');

        $io->success(\sprintf('%d board game associations imported.', $count));

        return Command::SUCCESS;
    }
}
