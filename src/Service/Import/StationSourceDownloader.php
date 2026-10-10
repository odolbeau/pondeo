<?php

declare(strict_types=1);

namespace App\Service\Import;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Refreshes `resources/geo/gares-voyageurs.csv` from the "Gares de voyageurs"
 * dataset (SNCF Gares & Connexions, data.gouv.fr, ODbL). The source writes
 * the position as "latitude, longitude" in one cell; the vendored file splits
 * it into `lat` and `lon`. A station with several UIC codes keeps them all,
 * joined by ";", in `code_uic` — `id_gare` is the stable key.
 */
final readonly class StationSourceDownloader
{
    // Stable resource redirect, always resolves to the publisher's current version.
    private const string STATIONS_CSV_URL = 'https://www.data.gouv.fr/api/1/datasets/r/cbacca02-6925-4a46-aab6-7194debbb9b7';

    private const array CSV_COLUMNS = ['id_gare', 'name', 'lat', 'lon', 'code_commune', 'code_uic'];

    public function __construct(
        private HttpClientInterface $httpClient,
        private CsvReader $csvReader,
    ) {
    }

    /**
     * @param \Closure(string): void $progress
     *
     * @throws ExceptionInterface when the download fails
     */
    public function download(string $targetDir, \Closure $progress): void
    {
        $source = tempnam(sys_get_temp_dir(), 'gares-');
        if (false === $source) {
            throw new \RuntimeException('Cannot create a temporary file.');
        }

        try {
            file_put_contents($source, $this->httpClient->request('GET', self::STATIONS_CSV_URL)->getContent());

            $progress('Downloading gares-voyageurs.csv...');
            $progress(\sprintf('  %d stations', $this->writeStations($source, $targetDir.'/gares-voyageurs.csv')));
        } finally {
            unlink($source);
        }
    }

    private function writeStations(string $sourcePath, string $targetPath): int
    {
        $out = fopen($targetPath, 'w') ?: throw new \RuntimeException("Cannot write {$targetPath}");
        fputcsv($out, self::CSV_COLUMNS, escape: '\\');

        $count = 0;
        foreach ($this->csvReader->read($sourcePath, ';') as $row) {
            // The file starts with a UTF-8 BOM, which ends up glued to the first column name.
            $row = array_combine(array_map(static fn (string $name): string => ltrim($name, "\u{feff}"), array_keys($row)), $row);

            [$lat, $lon] = array_map(trim(...), explode(',', $row['Position géographique']) + [1 => '']);
            if (!is_numeric($lat) || !is_numeric($lon)) {
                throw new \RuntimeException(\sprintf('Unexpected position "%s" for station "%s".', $row['Position géographique'], $row['Nom_Gare']));
            }

            fputcsv($out, [$row['Id_Gare'], $row['Nom_Gare'], $lat, $lon, $row['Code commune'], $row['Code_UIC']], escape: '\\');
            ++$count;
        }

        fclose($out);

        return $count;
    }
}
