<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Import\ImportSource;
use App\Service\Import\RentImporter;
use App\Service\Import\RentSourceDownloader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

#[AsCommand(
    name: 'app:import:criteria:rents',
    description: 'Import the advertised rent per m² criteria from resources/geo/loyers-annonces.csv, averaged per unité urbaine',
    help: <<<'HELP'
        Idempotent — safe to re-run. Import the zones first.

        Communes are weighted by their unfurnished private rentals, read from
        resources/geo/logement-recensement.csv (latest census year).

        With <comment>--download</comment>, the vendored file is refreshed first from the ANIL
        "Carte des loyers" on data.gouv.fr (four files of about 5 MB).
        HELP,
)]
#[ImportSource('loyers-annonces.csv', 'logement-recensement.csv')]
final class ImportCriteriaRentsCommand extends Command
{
    public function __construct(
        private readonly RentImporter $rentImporter,
        private readonly RentSourceDownloader $rentSourceDownloader,
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
                $this->rentSourceDownloader->download($dir, $io->writeln(...));
            } catch (ExceptionInterface $e) {
                $io->error(\sprintf('Download failed: %s', $e->getMessage()));

                return Command::FAILURE;
            }

            $io->note('Rents refreshed. Review with `git diff resources/geo/` before committing.');
        }

        $count = $this->rentImporter->import($dir.'/loyers-annonces.csv', $dir.'/logement-recensement.csv');

        $io->success(\sprintf('%d rent values imported (one per unité urbaine and property type).', $count));

        return Command::SUCCESS;
    }
}
