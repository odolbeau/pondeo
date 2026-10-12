<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Import\ImportSource;
use App\Service\Import\SocioProfessionalImporter;
use App\Service\Import\SocioProfessionalSourceDownloader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

#[AsCommand(
    name: 'app:import:criteria:pcs',
    description: 'Import the share of each socio-professional category from resources/geo/pcs-recensement.csv, summed per unité urbaine',
    help: <<<'HELP'
        Idempotent — safe to re-run. Import the zones first.

        With <comment>--download</comment>, the vendored file is refreshed first from INSEE's Mélodi API
        (a hundred or so paged requests, throttled: expect several minutes).
        HELP,
)]
#[ImportSource('pcs-recensement.csv')]
final class ImportCriteriaPcsCommand extends Command
{
    public function __construct(
        private readonly SocioProfessionalImporter $socioProfessionalImporter,
        private readonly SocioProfessionalSourceDownloader $socioProfessionalSourceDownloader,
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
                $this->socioProfessionalSourceDownloader->download($dir, $io->writeln(...));
            } catch (ExceptionInterface $e) {
                $io->error(\sprintf('Download failed: %s', $e->getMessage()));

                return Command::FAILURE;
            }

            $io->note('Socio-professional categories refreshed. Review with `git diff resources/geo/` before committing.');
        }

        $count = $this->socioProfessionalImporter->import($dir.'/pcs-recensement.csv');

        $io->success(\sprintf('%d socio-professional share values imported (per unité urbaine and census year).', $count));

        return Command::SUCCESS;
    }
}
