<?php

declare(strict_types=1);

namespace App\Service\Import;

use Doctrine\DBAL\Connection;

/**
 * Stores on each unité urbaine its population by sex and five-year age
 * bracket (see `docs/population-pyramid.md`), read from the vendored CSV.
 */
final readonly class AgePyramidImporter
{
    private const int BRACKETS = AgePyramidSourceDownloader::LAST_BRACKET_START / AgePyramidSourceDownloader::BRACKET_SIZE + 1;

    public function __construct(
        private Connection $connection,
        private CsvReader $csvReader,
    ) {
    }

    /**
     * @return int number of unités urbaine updated
     */
    public function import(string $csvPath): int
    {
        /** @var array<string, array{year: int, women: list<int>, men: list<int>}> $pyramids indexed by unité urbaine code */
        $pyramids = [];
        foreach ($this->csvReader->read($csvPath) as $row) {
            $pyramids[$row['code_insee']] ??= ['year' => (int) $row['year'], 'women' => array_fill(0, self::BRACKETS, 0), 'men' => array_fill(0, self::BRACKETS, 0)];
            $pyramids[$row['code_insee']]['F' === $row['sex'] ? 'women' : 'men'][intdiv((int) $row['age_from'], AgePyramidSourceDownloader::BRACKET_SIZE)] = (int) $row['count'];
        }

        $count = 0;
        foreach ($pyramids as $code => $pyramid) {
            $count += (int) $this->connection->executeStatement(
                'UPDATE unite_urbaine SET age_pyramid = :pyramid WHERE insee_code = :code',
                ['pyramid' => json_encode($pyramid, \JSON_THROW_ON_ERROR), 'code' => $code],
            );
        }

        return $count;
    }
}
