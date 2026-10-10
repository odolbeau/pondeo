<?php

declare(strict_types=1);

namespace App\Service\Import;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Refreshes `resources/geo/centrales-nucleaires.csv` from EDF's "Centrales de
 * production nucléaire" dataset (opendata.edf.fr via data.gouv.fr, Licence
 * Ouverte). The source has one row per reactor; the vendored file has one row
 * per plant, with the reactor count and the summed installed power.
 */
final readonly class NuclearPlantSourceDownloader
{
    // Stable resource redirect, always resolves to the publisher's current version.
    private const string PLANTS_CSV_URL = 'https://www.data.gouv.fr/api/1/datasets/r/e66a02b6-97b4-44dd-9c2d-f82b238d87b7';

    private const array CSV_COLUMNS = ['id', 'name', 'lat', 'lon', 'reactors', 'power_mw'];

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
        $source = tempnam(sys_get_temp_dir(), 'centrales-');
        if (false === $source) {
            throw new \RuntimeException('Cannot create a temporary file.');
        }

        try {
            file_put_contents($source, $this->httpClient->request('GET', self::PLANTS_CSV_URL)->getContent());

            $progress('Downloading centrales-nucleaires.csv...');
            $progress(\sprintf('  %d plants', $this->writePlants($source, $targetDir.'/centrales-nucleaires.csv')));
        } finally {
            unlink($source);
        }
    }

    private function writePlants(string $sourcePath, string $targetPath): int
    {
        /** @var array<string, array{name: string, lat: string, lon: string, reactors: int, power: float}> $plants */
        $plants = [];
        foreach ($this->csvReader->read($sourcePath) as $row) {
            $id = $row['Centrale'];

            [$lat, $lon] = array_map(trim(...), explode(',', $row['Point gps (wsg84)']) + [1 => '']);
            if (!is_numeric($lat) || !is_numeric($lon)) {
                throw new \RuntimeException(\sprintf('Unexpected position "%s" for plant "%s".', $row['Point gps (wsg84)'], $id));
            }

            $plants[$id] ??= ['name' => $this->displayName($id), 'lat' => $lat, 'lon' => $lon, 'reactors' => 0, 'power' => 0.0];
            ++$plants[$id]['reactors'];
            $plants[$id]['power'] += (float) $row['Puissance installée '];
        }

        $out = fopen($targetPath, 'w') ?: throw new \RuntimeException("Cannot write {$targetPath}");
        fputcsv($out, self::CSV_COLUMNS, escape: '\\');
        foreach ($plants as $id => $plant) {
            fputcsv($out, [$id, $plant['name'], $plant['lat'], $plant['lon'], $plant['reactors'], (int) $plant['power']], escape: '\\');
        }

        fclose($out);

        return \count($plants);
    }

    /** "BLAYAIS (LE)" -> "Le Blayais". */
    private function displayName(string $sourceName): string
    {
        if (1 === preg_match('/^(.+) \((LE|LA|L\')\)$/', $sourceName, $matches)) {
            $sourceName = $matches[2].("L'" === $matches[2] ? '' : ' ').$matches[1];
        }

        $title = mb_convert_case($sourceName, \MB_CASE_TITLE);

        return preg_replace_callback('/-(En|Sur|Des|De)(?=-|$)/', static fn (array $m): string => '-'.strtolower($m[1]), $title) ?? $title;
    }
}
