<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Import\HospitalImporter;
use App\Service\Import\HospitalSourceDownloader;
use App\Service\Import\ImportSource;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

#[AsCommand(
    name: 'app:import:pois:hospitals',
    description: 'Import the hospitals (resources/geo/hopitaux.csv) as POIs',
    help: <<<'HELP'
        Idempotent upsert — safe to re-run. Run <comment>app:import:zones</comment> first so each hospital is
        attached to its unité urbaine.

        With <comment>--download</comment>, the vendored file is refreshed first from the FINESS directory
        (Agence du Numérique en Santé, data.gouv.fr, Licence Ouverte). The download is about 110 MB; see
        docs/poi-hospitals.md.
        HELP,
)]
#[ImportSource('hopitaux.csv', 'communes.geojson')]
final class ImportPoisHospitalsCommand extends Command
{
    public function __construct(
        private readonly HospitalImporter $hospitalImporter,
        private readonly HospitalSourceDownloader $hospitalSourceDownloader,
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
                $this->hospitalSourceDownloader->download($dir, $io->writeln(...));
            } catch (ExceptionInterface $e) {
                $io->error(\sprintf('Download failed: %s', $e->getMessage()));

                return Command::FAILURE;
            }

            $io->note('Reference file refreshed. Review with `git diff resources/geo/` before committing.');
        }

        $counts = $this->hospitalImporter->import($dir.'/hopitaux.csv');

        $io->success(\sprintf('%d hospitals imported, %d of them with an emergency department.', $counts['hospitals'], $counts['emergencies']));

        return Command::SUCCESS;
    }
}
