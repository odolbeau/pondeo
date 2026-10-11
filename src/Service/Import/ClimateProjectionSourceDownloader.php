<?php

declare(strict_types=1);

namespace App\Service\Import;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Computes `resources/geo/climat-projections.csv` from the Open-Meteo Climate
 * API (see `docs/criteria-climate-projections.md`): one value per unité
 * urbaine, read at its centroid snapped to a 0.1° grid, the units sharing a
 * cell sharing its figures.
 *
 * Two steps. Every API answer is first archived as received (per cell, see
 * {@see ClimateProjectionArchive}), which makes the run resumable. The CSV is
 * then derived from the archive alone, offline, once every cell is there.
 */
final readonly class ClimateProjectionSourceDownloader
{
    public const string FILE = 'climat-projections.csv';

    public const int YEAR = 2050;

    // EC_Earth3P_HR is left out: it has no data for 2050.
    public const array MODELS = ['CMCC_CM2_VHR4', 'MPI_ESM1_2_XR', 'MRI_AGCM3_2_S'];

    // The API's last day is 2050-12-31.
    public const array BASELINE = ['1991-01-01', '2020-12-31'];

    public const array WINDOW = ['2041-01-01', '2050-12-31'];

    // The reference period only needs the mean temperature and the rain.
    public const array BASELINE_VARIABLES = ['temperature_2m_mean', 'precipitation_sum'];

    public const array WINDOW_VARIABLES = ['temperature_2m_max', 'temperature_2m_min', 'temperature_2m_mean', 'precipitation_sum'];

    // Stops the download when it is projected to cost more calls than this.
    public const int CALL_BUDGET = 1_800_000;

    // The budget is checked once this many cells have been fetched in a run.
    private const int CELLS_BEFORE_BUDGET_CHECK = 20;

    // Measured on the free API: one call is about this many variable-days (a variable, a model, a day).
    private const int VARIABLE_DAYS_PER_CALL = 130;

    private const int CELLS_PER_REQUEST = 10;

    private const string FREE_URL = 'https://climate-api.open-meteo.com/v1/climate';

    private const string PAID_URL = 'https://customer-climate-api.open-meteo.com/v1/climate';

    private HttpClientInterface $httpClient;

    public function __construct(
        private Connection $connection,
        private ClimateProjectionDeriver $deriver,
        private ClimateProjectionArchive $archive,
        #[Autowire(env: 'OPENMETEO_API_KEY')]
        private string $apiKey,
    ) {
        // Not the framework's client: its logger would write the API key, a query parameter, to the logs.
        // Without a key, the free API's per-minute quota is waited out; with one, there is none.
        $this->httpClient = new RetryableHttpClient(HttpClient::create(), new OpenMeteoRetryStrategy('' === $apiKey ? 61_000 : 5_000), 5);
    }

    /**
     * @param \Closure(string): void $progress
     *
     * @return array{done: int, total: int, written: bool, calls: int, message: string|null} calls is the estimated cost of this run; the CSV is only written when every cell is archived
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

        $missing = array_values(array_filter($cells, fn (string $cell): bool => !$this->isArchived($cell)));
        $progress(\sprintf('%d cells for %d units, %d already archived in %s', \count($cells), \count($units), \count($cells) - \count($missing), $this->archive->getDir()));

        $calls = 0;
        $fetched = 0;
        foreach (array_chunk($missing, self::CELLS_PER_REQUEST) as $chunk) {
            try {
                $calls += $this->archiveCells($chunk);
            } catch (HttpExceptionInterface $e) {
                $body = $e->getResponse()->getContent(false);
                $stopped = 429 === $e->getResponse()->getStatusCode();

                return $this->result($cells, $calls, false, \sprintf('Open-Meteo answered HTTP %d%s: %s', $e->getResponse()->getStatusCode(), $stopped ? ' (quota reached)' : '', $body));
            } catch (TransportExceptionInterface $e) {
                return $this->result($cells, $calls, false, 'Transport error: '.str_replace($this->apiKey, '***', $e->getMessage()));
            }

            $fetched += \count($chunk);
            $progress(\sprintf('  %d/%d cells, about %d calls so far', \count($cells) - \count($missing) + $fetched, \count($cells), $calls));

            $projected = (int) round($calls / $fetched * \count($missing));
            if ($fetched >= self::CELLS_BEFORE_BUDGET_CHECK && $projected > self::CALL_BUDGET) {
                return $this->result($cells, $calls, false, \sprintf('Projected cost of the remaining run is %d calls, above the budget of %d.', $projected, self::CALL_BUDGET));
            }
        }

        $this->writeCsv($targetDir.'/'.self::FILE, $cellOfUnit);

        return $this->result($cells, $calls, true, null);
    }

    /**
     * @param list<string> $cells
     *
     * @return array{done: int, total: int, written: bool, calls: int, message: string|null}
     */
    private function result(array $cells, int $calls, bool $written, ?string $message): array
    {
        $done = \count(array_filter($cells, $this->isArchived(...)));

        return ['done' => $done, 'total' => \count($cells), 'written' => $written, 'calls' => $calls, 'message' => $message];
    }

    private function isArchived(string $cell): bool
    {
        return $this->archive->has($cell, self::BASELINE, self::MODELS, self::BASELINE_VARIABLES)
            && $this->archive->has($cell, self::WINDOW, self::MODELS, self::WINDOW_VARIABLES);
    }

    /**
     * @param list<string> $cells
     *
     * @return int estimated number of API calls spent
     */
    private function archiveCells(array $cells): int
    {
        $calls = 0;
        foreach ([[self::BASELINE, self::BASELINE_VARIABLES], [self::WINDOW, self::WINDOW_VARIABLES]] as [$period, $variables]) {
            $wanted = array_values(array_filter($cells, fn (string $cell): bool => !$this->archive->has($cell, $period, self::MODELS, $variables)));
            if ([] === $wanted) {
                continue;
            }

            $locations = $this->fetch($wanted, $period, $variables);
            foreach ($wanted as $i => $cell) {
                $this->archive->write($cell, $period, self::MODELS, $variables, $locations[$i]);
            }

            $days = (int) round((strtotime($period[1]) - strtotime($period[0])) / 86400) + 1;
            $cost = (int) ceil(\count($wanted) * \count($variables) * \count(self::MODELS) * $days / self::VARIABLE_DAYS_PER_CALL);
            $calls += $cost;
            $this->archive->logRequest(\sprintf('%s %s..%s %d cells, about %d calls', date('c'), $period[0], $period[1], \count($wanted), $cost));
        }

        return $calls;
    }

    /**
     * @param list<string>          $cells
     * @param array{string, string} $period
     * @param list<string>          $variables
     *
     * @return list<array<string, mixed>> the API's answer for each cell, in order
     */
    private function fetch(array $cells, array $period, array $variables): array
    {
        $latitudes = [];
        $longitudes = [];
        foreach ($cells as $cell) {
            [$latitudes[], $longitudes[]] = explode(',', $cell);
        }

        $query = [
            'latitude' => implode(',', $latitudes),
            'longitude' => implode(',', $longitudes),
            'daily' => implode(',', $variables),
            'models' => implode(',', self::MODELS),
            'start_date' => $period[0],
            'end_date' => $period[1],
        ];
        if ('' !== $this->apiKey) {
            $query['apikey'] = $this->apiKey;
        }

        $response = $this->httpClient->request('GET', '' === $this->apiKey ? self::FREE_URL : self::PAID_URL, ['query' => $query]);
        /** @var array<string, mixed>|list<array<string, mixed>> $data */
        $data = $response->toArray();

        // A single location comes back as an object instead of a list.
        /** @var list<array<string, mixed>> $locations */
        $locations = array_is_list($data) ? $data : [$data];

        return $locations;
    }

    /**
     * @param array<string, string> $cellOfUnit
     */
    private function writeCsv(string $path, array $cellOfUnit): void
    {
        /** @var array<string, array{hotDays: float, tropicalNights: float, warming: float, heatWaves: float, precipitationChange: float|null, uncertainty: float|null}|null> $figures */
        $figures = [];
        foreach (array_unique($cellOfUnit) as $cell) {
            $figures[$cell] = $this->deriveCell($cell);
        }

        $handle = fopen($path, 'w') ?: throw new \RuntimeException("Cannot write {$path}");
        fputcsv($handle, ['code_unite_urbaine', 'year', 'measure', 'value'], escape: '\\');
        foreach ($cellOfUnit as $code => $cell) {
            $cellFigures = $figures[$cell];
            if (null === $cellFigures) {
                continue;
            }

            $rows = [
                'hot_days' => round($cellFigures['hotDays'], 1),
                'tropical_nights' => round($cellFigures['tropicalNights'], 1),
                'warming' => round($cellFigures['warming'], 2),
                'heat_waves' => round($cellFigures['heatWaves'], 2),
                'precipitation_change' => null === $cellFigures['precipitationChange'] ? null : round($cellFigures['precipitationChange'], 1),
                'uncertainty' => null === $cellFigures['uncertainty'] ? null : round($cellFigures['uncertainty'], 2),
            ];
            foreach ($rows as $measure => $value) {
                if (null !== $value) {
                    fputcsv($handle, [$code, self::YEAR, $measure, $value], escape: '\\');
                }
            }
        }

        fclose($handle);
    }

    /**
     * @return array{hotDays: float, tropicalNights: float, warming: float, heatWaves: float, precipitationChange: float|null, uncertainty: float|null}|null
     */
    private function deriveCell(string $cell): ?array
    {
        $baseline = $this->archive->read($cell, self::BASELINE, self::MODELS, self::BASELINE_VARIABLES)['daily'];
        $window = $this->archive->read($cell, self::WINDOW, self::MODELS, self::WINDOW_VARIABLES)['daily'];

        $baselineByModel = [];
        $windowByModel = [];
        foreach (self::MODELS as $model) {
            /** @var list<string> $baselineTime */
            $baselineTime = $baseline['time'];
            /** @var list<string> $windowTime */
            $windowTime = $window['time'];
            /** @var list<float|null> $mean */
            $mean = $baseline["temperature_2m_mean_{$model}"] ?? [];
            /** @var list<float|null> $rain */
            $rain = $baseline["precipitation_sum_{$model}"] ?? [];
            $baselineByModel[$model] = ['time' => $baselineTime, 'mean' => $mean, 'precipitation' => $rain];

            /** @var list<float|null> $max */
            $max = $window["temperature_2m_max_{$model}"] ?? [];
            /** @var list<float|null> $min */
            $min = $window["temperature_2m_min_{$model}"] ?? [];
            /** @var list<float|null> $windowMean */
            $windowMean = $window["temperature_2m_mean_{$model}"] ?? [];
            /** @var list<float|null> $windowRain */
            $windowRain = $window["precipitation_sum_{$model}"] ?? [];
            $windowByModel[$model] = ['time' => $windowTime, 'max' => $max, 'min' => $min, 'mean' => $windowMean, 'precipitation' => $windowRain];
        }

        return $this->deriver->derive($baselineByModel, $windowByModel);
    }
}
