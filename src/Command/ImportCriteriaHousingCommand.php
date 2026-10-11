<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Import\HousingImporter;
use App\Service\Import\HousingSourceDownloader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

#[AsCommand(
    name: 'app:import:criteria:housing',
    description: 'Import the shares of owner-occupiers, social housing and vacant housing from resources/geo/logement-recensement.csv, summed per unité urbaine',
    help: <<<'HELP'
        Idempotent — safe to re-run. Import the zones first.

        With <comment>--download</comment>, the vendored file is refreshed first from INSEE's Mélodi API
        (a couple of hundred paged requests, throttled: expect several minutes).
        HELP,
)]
final class ImportCriteriaHousingCommand extends Command
{
    public function __construct(
        private readonly HousingImporter $housingImporter,
        private readonly HousingSourceDownloader $housingSourceDownloader,
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
                $this->housingSourceDownloader->download($dir, $io->writeln(...));
            } catch (ExceptionInterface $e) {
                $io->error(\sprintf('Download failed: %s', $e->getMessage()));

                return Command::FAILURE;
            }

            $io->note('Housing figures refreshed. Review with `git diff resources/geo/` before committing.');
        }

        $count = $this->housingImporter->import($dir.'/logement-recensement.csv');

        $io->success(\sprintf('%d housing share values imported (per unité urbaine and census year).', $count));

        return Command::SUCCESS;
    }
}
