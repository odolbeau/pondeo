<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Import\RealEstatePriceImporter;
use App\Service\Import\RealEstatePriceSourceDownloader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

#[AsCommand(
    name: 'app:import:criteria:real-estate-prices',
    description: 'Import the price per m² criteria (all years) from resources/geo/prix-immobilier.csv, averaged per unité urbaine',
    help: <<<'HELP'
        Idempotent — safe to re-run.

        With <comment>--download</comment>, the vendored file is refreshed first from the
        "Statistiques DVF" monthly file on data.gouv.fr (one download of about 270 MB).
        HELP,
)]
final class ImportCriteriaRealEstatePricesCommand extends Command
{
    public function __construct(
        private readonly RealEstatePriceImporter $realEstatePriceImporter,
        private readonly RealEstatePriceSourceDownloader $realEstatePriceSourceDownloader,
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
                $this->realEstatePriceSourceDownloader->download($dir, $io->writeln(...));
            } catch (ExceptionInterface $e) {
                $io->error(\sprintf('Download failed: %s', $e->getMessage()));

                return Command::FAILURE;
            }

            $io->note('Real estate prices refreshed. Review with `git diff resources/geo/` before committing.');
        }

        $count = $this->realEstatePriceImporter->import($dir.'/prix-immobilier.csv');

        $io->success(\sprintf('%d price values imported (one per unité urbaine, property type and year).', $count));

        return Command::SUCCESS;
    }
}
