<?php

declare(strict_types=1);

namespace App\Service\Import;

/**
 * Streams a CSV file as associative rows keyed by its header — shared by
 * every importer under `src/Service/Import/` reading a vendored
 * `resources/geo/*.csv` file.
 */
final class CsvReader
{
    /**
     * @return iterable<array<string, string>>
     */
    public function read(string $csvPath): iterable
    {
        $handle = fopen($csvPath, 'r') ?: throw new \RuntimeException("Cannot read {$csvPath}");
        $header = array_map(strval(...), fgetcsv($handle, escape: '\\') ?: throw new \RuntimeException("Empty CSV: {$csvPath}"));

        while (false !== ($row = fgetcsv($handle, escape: '\\'))) {
            /** @var array<string, string> $associative */
            $associative = array_combine($header, array_map(strval(...), $row));
            yield $associative;
        }

        fclose($handle);
    }
}
