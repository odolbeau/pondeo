<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Schema\DbmlExporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:db:dbml',
    description: 'Export the database schema as DBML (for dbdiagram.io)',
    help: <<<'HELP'
        Prints the schema as DBML on stdout; paste it into the editor of
        https://dbdiagram.io, or redirect it: <comment>bin/console app:db:dbml > schema.dbml</comment>.
        With <comment>--output</comment> it is written to that file instead.

        The schema is derived from the Doctrine mapping, not from a live database, so
        constraints added by hand in migrations (e.g. the CHECK on criterion_value) are
        not part of the export.
        HELP,
)]
final class DbDbmlCommand extends Command
{
    public function __construct(
        private readonly DbmlExporter $dbmlExporter,
    ) {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Write the DBML to this file instead of stdout')]
        ?string $output = null,
    ): int {
        $dbml = $this->dbmlExporter->export();

        if (null === $output) {
            $io->write($dbml, false, SymfonyStyle::OUTPUT_RAW);

            return Command::SUCCESS;
        }

        if (false === file_put_contents($output, $dbml)) {
            $io->error(\sprintf('Could not write "%s".', $output));

            return Command::FAILURE;
        }

        $io->success(\sprintf('Schema written to %s.', $output));

        return Command::SUCCESS;
    }
}
