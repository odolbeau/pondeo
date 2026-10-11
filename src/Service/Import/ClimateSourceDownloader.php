<?php

declare(strict_types=1);

namespace App\Service\Import;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpClient\Retry\GenericRetryStrategy;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Computes `resources/geo/climat-normales.csv`: the 1991-2020 climate normals
 * of each unité urbaine, from the Open-Meteo historical weather API taken at
 * the unit's centroid (see `docs/criteria-climate.md`).
 *
 * Units whose centroids fall in the same 0.1 degree cell of the ERA5-Land
 * grid share one location. The file is appended to as it goes and units
 * already in it are skipped, so an interrupted run (or one stopped by the
 * API quotas) resumes where it stopped.
 */
final readonly class ClimateSourceDownloader
{
    public const string FILE = 'climat-normales.csv';

    public const int PERIOD = 2020;

    private const string URL = 'https://archive-api.open-meteo.com/v1/archive';

    private const array CSV_COLUMNS = ['code_unite_urbaine', 'year', 'measure', 'value'];

    private HttpClientInterface $httpClient;

    public function __construct(
        private Connection $connection,
        private ClimateNormalsCalculator $calculator,
        HttpClientInterface $httpClient,
        // The weight of a request grows with its locations and days, but the
        // minutely quota is checked before it, so a chunk is capped to keep
        // the response and the wait after a 429 reasonable.
        private int $locationsPerRequest = 4,
        int $retryDelayMilliseconds = 61_000,
    ) {
        // The minutely quota answers 429 "try again in one minute".
        $this->httpClient = new RetryableHttpClient(
            $httpClient,
            new GenericRetryStrategy([429, 500, 502, 503, 504], $retryDelayMilliseconds, 1.0, 0, 0.0),
            3,
        );
    }

    /**
     * @param \Closure(string): void $progress
     *
     * @return int number of units written by this run
     */
    public function download(string $targetDir, \Closure $progress): int
    {
        $path = $targetDir.'/'.self::FILE;
        $done = $this->readDone($path);

        /** @var list<array{insee_code: string, lon: float|string, lat: float|string}> $units */
        $units = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT uu.insee_code, ST_X(uu.centroid::geometry) AS lon, ST_Y(uu.centroid::geometry) AS lat
                FROM unite_urbaine uu
                WHERE uu.centroid IS NOT NULL
                ORDER BY uu.insee_code
                SQL,
        );

        /** @var array<string, array{lat: float, lon: float, units: list<string>}> $cells */
        $cells = [];
        foreach ($units as $unit) {
            if (isset($done[$unit['insee_code']])) {
                continue;
            }

            $lat = round((float) $unit['lat'], 1);
            $lon = round((float) $unit['lon'], 1);
            $cells[$lat.','.$lon] ??= ['lat' => $lat, 'lon' => $lon, 'units' => []];
            $cells[$lat.','.$lon]['units'][] = $unit['insee_code'];
        }

        $progress(\sprintf('%d units done, %d to go in %d distinct 0.1° cells.', \count($done), \count($units) - \count($done), \count($cells)));

        $isNew = !is_file($path) || 0 === filesize($path);
        $out = fopen($path, 'a') ?: throw new \RuntimeException('Cannot write '.self::FILE);
        if ($isNew) {
            fputcsv($out, self::CSV_COLUMNS, escape: '\\');
        }

        $written = 0;
        $cellCount = 0;
        foreach (array_chunk($cells, max(1, $this->locationsPerRequest)) as $chunk) {
            foreach ($this->fetchDaily($chunk) as $i => $daily) {
                $normals = $this->calculator->normals($daily);
                foreach ($chunk[$i]['units'] as $code) {
                    foreach ($normals as $measure => $value) {
                        fputcsv($out, [$code, self::PERIOD, $measure, round($value, 1)], escape: '\\');
                    }

                    ++$written;
                }
            }

            // A chunk is complete once flushed: that is what makes a resume safe.
            fflush($out);
            $cellCount += \count($chunk);
            $progress(\sprintf('  %d/%d cells, %d units written', $cellCount, \count($cells), $written));
        }

        fclose($out);

        return $written;
    }

    /**
     * @return array<string, true> the unit codes already in the file
     */
    private function readDone(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $done = [];
        foreach (new CsvReader()->read($path) as $row) {
            $done[$row['code_unite_urbaine']] = true;
        }

        return $done;
    }

    /**
     * @param list<array{lat: float, lon: float}> $locations
     *
     * @return list<array<string, list<mixed>>> the `daily` block of each location, in order
     */
    private function fetchDaily(array $locations): array
    {
        /** @var array<mixed> $data */
        $data = $this->httpClient->request('GET', self::URL, ['query' => [
            'latitude' => implode(',', array_column($locations, 'lat')),
            'longitude' => implode(',', array_column($locations, 'lon')),
            'start_date' => '1991-01-01',
            'end_date' => '2020-12-31',
            'daily' => implode(',', ClimateNormalsCalculator::DAILY_VARIABLES),
            'timezone' => 'auto',
        ], 'timeout' => 300])->toArray();

        // A single location is answered as an object, several as a list.
        $results = isset($data['daily']) ? [$data] : $data;
        if (\count($results) !== \count($locations)) {
            throw new \RuntimeException(\sprintf('Open-Meteo answered %d locations for %d requested.', \count($results), \count($locations)));
        }

        /** @var list<array<string, list<mixed>>> $daily */
        $daily = array_map(static fn (mixed $result): array => \is_array($result) && \is_array($result['daily'] ?? null) ? $result['daily'] : throw new \RuntimeException('Open-Meteo answered no daily data.'), array_values($results));

        return $daily;
    }
}
