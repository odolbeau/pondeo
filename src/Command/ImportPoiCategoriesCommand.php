<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Import\PoiCategoryTreeSynchronizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:import:poi-categories',
    description: 'Apply the tree of POI categories (resources/poi-categories.yaml)',
    help: <<<'HELP'
        Idempotent upsert — safe to re-run. Creates the missing categories and updates the label and
        parent of the others in place, so editing the tree needs no re-import of the POIs themselves.
        A category the file no longer lists is left alone: POIs may still point to it.
        HELP,
)]
final class ImportPoiCategoriesCommand extends Command
{
    public function __construct(private readonly PoiCategoryTreeSynchronizer $poiCategoryTreeSynchronizer)
    {
        parent::__construct();
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $count = $this->poiCategoryTreeSynchronizer->synchronize();

        $io->success(\sprintf('%d POI categories synchronized.', $count));

        return Command::SUCCESS;
    }
}
