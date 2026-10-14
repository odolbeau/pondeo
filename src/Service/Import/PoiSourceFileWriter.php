<?php

declare(strict_types=1);

namespace App\Service\Import;

/** Writes the vendored file of a PoiCsvImporter source. */
final readonly class PoiSourceFileWriter
{
    private const array COLUMNS = ['id', 'name', 'lat', 'lon', 'address', 'activity'];

    /**
     * @param array<string, array{name: string, lat: float|string, lon: float|string, address: string, activity: string}> $pois by id
     */
    public function write(string $targetPath, array $pois): int
    {
        if ([] === $pois) {
            throw new \RuntimeException('Unexpected response: no POIs.');
        }

        // Sorted so a refresh only diffs on real changes.
        ksort($pois);

        $out = fopen($targetPath, 'w') ?: throw new \RuntimeException("Cannot write {$targetPath}");
        fputcsv($out, self::COLUMNS, escape: '\\');
        foreach ($pois as $id => $poi) {
            fputcsv($out, [$id, $poi['name'], $poi['lat'], $poi['lon'], $poi['address'], $poi['activity']], escape: '\\');
        }

        fclose($out);

        return \count($pois);
    }
}
