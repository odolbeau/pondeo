<?php

declare(strict_types=1);

namespace App\Service\Import;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Computes `resources/geo/climat-projections.csv` from the Open-Meteo Climate
 * API (see `docs/criteria-climate-projections.md`): one value per unité
 * urbaine, read at its centroid snapped to a 0.1° grid, the units sharing a
 * cell sharing its figures. The API is metered per variable and day, so the
 * run is resumable: each cell is kept in a work file as soon as it is
 * computed, and the CSV is only written once every cell is done.
 */
final readonly class ClimateProjectionSourceDownloader
{
    public const string FILE = 'climat-projections.csv';

    public const string WORK_FILE = 'climate-projections-cells.csv';

    public const int YEAR = 2050;

    // EC_Earth3P_HR is left out: it has no data for 2050.
    public const array MODELS = ['CMCC_CM2_VHR4', 'MPI_ESM1_2_XR', 'MRI_AGCM3_2_S'];

    private const string API_URL = 'https://climate-api.open-meteo.com/v1/climate';

    // The API's last day is 2050-12-31.
    private const array BASELINE = ['1991-01-01', '2020-12-31'];

    private const array WINDOW = ['2041-01-01', '2050-12-31'];

    private const int CELLS_PER_REQUEST = 5;

    private const int THROTTLE_MICROSECONDS = 1_000_000;

    private HttpClientInterface $httpClient;

    public function __construct(
        HttpClientInterface $httpClient,
        private Connection $connection,
        private ClimateProjectionDeriver $deriver,
        #[Autowire('%kernel.project_dir%/var')]
        private string $workDir,
    ) {
        $this->httpClient = new RetryableHttpClient($httpClient, new OpenMeteoRetryStrategy(), 5);
    }

    /**
     * @param \Closure(string): void $progress
     *
     * @return array{done: int, total: int, written: bool} the CSV is only written when every cell is done
     */
    public function download(string $targetDir, \Closure $progress): array
    {
        /** @var list<array{insee_code: string, lon: float|string, lat: float|string}> $units */
        $units = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT uu.insee_code, ST_X(uu.centroid::geometry) AS lon, ST_Y(uu.centroid::geometry) AS lat
                FROM unite_urbaine uu
                WHERE uu.centroid IS NOT NULL
                ORDER BY uu.insee_code
                SQL,
        );

        /** @var array<string, string> $cellOfUnit */
        $cellOfUnit = [];
        foreach ($units as $unit) {
            $cellOfUnit[$unit['insee_code']] = \sprintf('%.1f,%.1f', round((float) $unit['lat'], 1), round((float) $unit['lon'], 1));
        }

        $cells = array_values(array_unique($cellOfUnit));
        sort($cells);

        $results = $this->loadWorkFile();
        $todo = array_values(array_diff($cells, array_keys($results)));
        $progress(\sprintf('%d cells for %d units, %d already done', \count($cells), \count($units), \count($cells) - \count($todo)));

        foreach (array_chunk($todo, self::CELLS_PER_REQUEST) as $chunk) {
            try {
                $derived = $this->deriveCells($chunk);
            } catch (HttpExceptionInterface $e) {
                if (429 !== $e->getResponse()->getStatusCode()) {
                    throw $e;
                }

                $progress('Quota reached: '.$e->getResponse()->getContent(false));

                return ['done' => \count($cells) - \count($todo), 'total' => \count($cells), 'written' => false];
            }

            $this->appendToWorkFile($derived);
            $results += $derived;
            $todo = array_values(array_diff($todo, $chunk));
            $progress(\sprintf('  %d/%d cells', \count($cells) - \count($todo), \count($cells)));

            usleep(self::THROTTLE_MICROSECONDS);
        }

        $this->writeCsv($targetDir.'/'.self::FILE, $cellOfUnit, $results);

        return ['done' => \count($cells), 'total' => \count($cells), 'written' => true];
    }

    /**
     * @param list<string> $cells "lat,lon"
     *
     * @return array<string, array{hotDays: float, tropicalNights: float, warming: float}|null>
     */
    private function deriveCells(array $cells): array
    {
        $baseline = $this->fetch($cells, self::BASELINE, ['temperature_2m_mean']);
        usleep(self::THROTTLE_MICROSECONDS);
        $future = $this->fetch($cells, self::WINDOW, ['temperature_2m_max', 'temperature_2m_min', 'temperature_2m_mean']);

        /**
         * @var list<array<string, array{time: list<string>, mean: list<float|null>}>>                                               $baseline
         * @var list<array<string, array{time: list<string>, max: list<float|null>, min: list<float|null>, mean: list<float|null>}>> $future
         */
        $derived = [];
        foreach ($cells as $i => $cell) {
            $derived[$cell] = $this->deriver->derive($baseline[$i] ?? [], $future[$i] ?? []);
        }

        return $derived;
    }

    /**
     * @param list<string>          $cells
     * @param array{string, string} $period
     * @param list<string>          $variables
     *
     * @return list<array<string, array<string, mixed>>> per cell, the daily series by model
     */
    private function fetch(array $cells, array $period, array $variables): array
    {
        $latitudes = [];
        $longitudes = [];
        foreach ($cells as $cell) {
            [$latitudes[], $longitudes[]] = explode(',', $cell);
        }

        $response = $this->httpClient->request('GET', self::API_URL, ['query' => [
            'latitude' => implode(',', $latitudes),
            'longitude' => implode(',', $longitudes),
            'daily' => implode(',', $variables),
            'models' => implode(',', self::MODELS),
            'start_date' => $period[0],
            'end_date' => $period[1],
        ]]);
        /** @var array{daily: array<string, mixed>}|list<array{daily: array<string, mixed>}> $data */
        $data = $response->toArray();
        // A single location comes back as an object instead of a list.
        $locations = array_is_list($data) ? $data : [$data];

        $series = [];
        foreach ($locations as $location) {
            /** @var array<string, list<float|string|null>> $daily */
            $daily = $location['daily'];
            $byModel = [];
            foreach (self::MODELS as $model) {
                $byModel[$model] = ['time' => $daily['time']];
                foreach (['max', 'min', 'mean'] as $stat) {
                    if (isset($daily["temperature_2m_{$stat}_{$model}"])) {
                        $byModel[$model][$stat] = $daily["temperature_2m_{$stat}_{$model}"];
                    }
                }
            }

            $series[] = $byModel;
        }

        return $series;
    }

    /**
     * @return array<string, array{hotDays: float, tropicalNights: float, warming: float}|null>
     */
    private function loadWorkFile(): array
    {
        $path = $this->workDir.'/'.self::WORK_FILE;
        if (!is_file($path)) {
            return [];
        }

        $results = [];
        $handle = fopen($path, 'r') ?: throw new \RuntimeException("Cannot read {$path}");
        while (false !== $row = fgetcsv($handle, escape: '\\')) {
            // An empty figure is a cell without data, remembered so that it is not asked again.
            $results[$row[0].','.$row[1]] = '' === $row[2] ? null : ['hotDays' => (float) $row[2], 'tropicalNights' => (float) $row[3], 'warming' => (float) $row[4]];
        }

        fclose($handle);

        return $results;
    }

    /**
     * @param array<string, array{hotDays: float, tropicalNights: float, warming: float}|null> $derived
     */
    private function appendToWorkFile(array $derived): void
    {
        $path = $this->workDir.'/'.self::WORK_FILE;
        $handle = fopen($path, 'a') ?: throw new \RuntimeException("Cannot write {$path}");
        foreach ($derived as $cell => $figures) {
            fputcsv($handle, [...explode(',', $cell), ...(null === $figures ? ['', '', ''] : array_values($figures))], escape: '\\');
        }

        fclose($handle);
    }

    /**
     * @param array<string, string>                                                            $cellOfUnit
     * @param array<string, array{hotDays: float, tropicalNights: float, warming: float}|null> $results
     */
    private function writeCsv(string $path, array $cellOfUnit, array $results): void
    {
        $handle = fopen($path, 'w') ?: throw new \RuntimeException("Cannot write {$path}");
        fputcsv($handle, ['code_unite_urbaine', 'year', 'measure', 'value'], escape: '\\');
        foreach ($cellOfUnit as $code => $cell) {
            $figures = $results[$cell] ?? null;
            if (null === $figures) {
                continue;
            }

            fputcsv($handle, [$code, self::YEAR, 'hot_days', round($figures['hotDays'], 1)], escape: '\\');
            fputcsv($handle, [$code, self::YEAR, 'tropical_nights', round($figures['tropicalNights'], 1)], escape: '\\');
            fputcsv($handle, [$code, self::YEAR, 'warming', round($figures['warming'], 2)], escape: '\\');
        }

        fclose($handle);
    }
}
