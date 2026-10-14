<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\BoardGameGroup;
use App\Service\Import\BoardGamePoiImporter;
use App\Service\Import\ImportSource;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:import:pois:board-games',
    description: 'Import the board game associations and stores (resources/geo/jeux-*.csv) as POIs',
    help: <<<'HELP'
        Idempotent upsert — safe to re-run. Run <comment>app:import:zones</comment> first so each place is
        attached to its unité urbaine.

        The files are vendored, reduced copies of an export provided by the directory's publisher
        (CC BY-SA 4.0): see docs/poi-board-game-associations.md for their columns.
        HELP,
)]
#[ImportSource('jeux-associations.csv', 'jeux-boutiques.csv', 'communes.geojson')]
final class ImportPoisBoardGamesCommand extends Command
{
    private const array FILES = [
        'jeux-associations.csv' => BoardGameGroup::Association,
        'jeux-boutiques.csv' => BoardGameGroup::Store,
    ];

    public function __construct(private readonly BoardGamePoiImporter $importer)
    {
        parent::__construct();
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $dir = \dirname(__DIR__, 2).'/resources/geo';

        foreach (self::FILES as $file => $group) {
            $io->success(\sprintf('%d × "%s" imported.', $this->importer->import($dir.'/'.$file, $group), $group->value));
        }

        return Command::SUCCESS;
    }
}
