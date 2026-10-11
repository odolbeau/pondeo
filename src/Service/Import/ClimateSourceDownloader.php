<?php

declare(strict_types=1);

namespace App\Service\Import;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\Retry\GenericRetryStrategy;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Computes `resources/geo/climat-normales.csv`: the 1991-2020 climate normals
 * of each unité urbaine, from the Open-Meteo historical weather API taken at
 * the unit's centroid (see `docs/criteria-climate.md`).
 *
 * Units whose centroids fall in the same 0.1 degree cell of the ERA5-Land
 * grid share one location. Every response is first archived, one gzip file
 * per cell, outside the repository: the CSV is then derived from the archive
 * alone, so an interrupted run resumes without any new call and a change of
 * derivation costs none.
 */
final readonly class ClimateSourceDownloader
{
    public const string FILE = 'climat-normales.csv';

    public const int PERIOD = 2020;

    private const string FREE_URL = 'https://archive-api.open-meteo.com/v1/archive';

    // The commercial host, reached with the API key.
    private const string CUSTOMER_URL = 'https://customer-archive-api.open-meteo.com/v1/archive';

    private const string START_DATE = '1991-01-01';

    private const string END_DATE = '2020-12-31';

    private const int DAYS = 10_958;

    private const array CSV_COLUMNS = ['code_unite_urbaine', 'year', 'measure', 'value'];

    private HttpClientInterface $httpClient;

    private string $archiveDir;

    public function __construct(
        private Connection $connection,
        private ClimateNormalsCalculator $calculator,
        HttpClientInterface $httpClient,
        #[Autowire(env: 'OPENMETEO_API_KEY')]
        private string $apiKey = '',
        #[Autowire(env: 'OPENMETEO_ARCHIVE_DIR')]
        string $archiveDir = '',
        // The quotas are checked before a request is weighted, so a chunk is
        // capped to keep each response reasonable.
        private int $locationsPerRequest = 5,
        // Only the free API has a minutely quota worth waiting for.
        ?int $retryDelayMilliseconds = null,
    ) {
        // The key travels in the URL: keep it out of the logs.
        if ($httpClient instanceof LoggerAwareInterface) {
            $httpClient->setLogger(new NullLogger());
        }

        $this->httpClient = new RetryableHttpClient(
            $httpClient,
            new GenericRetryStrategy([429, 500, 502, 503, 504], $retryDelayMilliseconds ?? ('' === $apiKey ? 61_000 : 1_000), 1.0, 0, 0.0),
            3,
        );

        $this->archiveDir = ('' !== $archiveDir ? $archiveDir : (getenv('HOME') ?: sys_get_temp_dir()).'/pondeo-data/open-meteo').'/normales';
    }

    /**
     * Estimated API calls of one location: the weight of a request grows with
     * its variables and days (10 variables over 14 days count as one call).
     */
    public static function callsPerLocation(): float
    {
        return \count(ClimateNormalsCalculator::DAILY_VARIABLES) / 10 * self::DAYS / 14;
    }

    /**
     * Sum of the calls recorded in the ledger of the archive.
     */
    public function spentCalls(): float
    {
        $ledger = $this->archiveDir.'/calls-ledger.csv';
        if (!is_file($ledger)) {
            return 0.0;
        }

        $total = 0.0;
        foreach (new CsvReader()->read($ledger) as $row) {
            $total += (float) $row['calls'];
        }

        return $total;
    }

    /**
     * @param \Closure(string): void $progress
     * @param int                    $maxCalls budget of API calls (ledger included); 0 for none
     *
     * @return int number of units written to the CSV
     */
    public function download(string $targetDir, \Closure $progress, int $maxCalls = 0): int
    {
        if (!is_dir($this->archiveDir) && !mkdir($this->archiveDir, 0o755, true) && !is_dir($this->archiveDir)) {
            throw new \RuntimeException('Cannot create the archive directory.');
        }

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
            $lat = round((float) $unit['lat'], 1);
            $lon = round((float) $unit['lon'], 1);
            $cells[$lat.','.$lon] ??= ['lat' => $lat, 'lon' => $lon, 'units' => []];
            $cells[$lat.','.$lon]['units'][] = $unit['insee_code'];
        }

        $pending = array_filter($cells, fn (array $cell): bool => !is_file($this->archivePath($cell['lat'], $cell['lon'])));
        $spent = $this->spentCalls();
        $projected = $spent + \count($pending) * self::callsPerLocation();
        $progress(\sprintf('%d units in %d cells, %d cells to fetch: about %s calls (%s already spent).', \count($units), \count($cells), \count($pending), number_format($projected - $spent, 0, '.', ' '), number_format($spent, 0, '.', ' ')));

        if ($maxCalls > 0 && $projected > $maxCalls) {
            $progress(\sprintf('Projected total %s calls exceeds the budget of %s: nothing fetched.', number_format($projected, 0, '.', ' '), number_format($maxCalls, 0, '.', ' ')));
        } else {
            $this->fetchPending($pending, $progress);
        }

        return $this->derive($targetDir.'/'.self::FILE, $cells);
    }

    /**
     * @param array<string, array{lat: float, lon: float, units: list<string>}> $pending
     * @param \Closure(string): void                                            $progress
     */
    private function fetchPending(array $pending, \Closure $progress): void
    {
        $done = 0;
        foreach (array_chunk($pending, max(1, $this->locationsPerRequest)) as $chunk) {
            foreach ($this->fetchWithRetry($chunk) as $i => $location) {
                $this->archive($chunk[$i]['lat'], $chunk[$i]['lon'], $location);
            }

            $this->recordCalls(\count($chunk));
            $done += \count($chunk);
            $progress(\sprintf('  %d/%d cells archived, about %s calls spent in total', $done, \count($pending), number_format($this->spentCalls(), 0, '.', ' ')));
        }
    }

    /**
     * Writes the CSV for every unit whose cell is archived.
     *
     * @param array<string, array{lat: float, lon: float, units: list<string>}> $cells
     *
     * @return int number of units written
     */
    private function derive(string $path, array $cells): int
    {
        $tmp = $path.'.tmp';
        $out = fopen($tmp, 'w') ?: throw new \RuntimeException('Cannot write '.self::FILE);
        fputcsv($out, self::CSV_COLUMNS, escape: '\\');

        $written = 0;
        foreach ($cells as $cell) {
            $archive = $this->archivePath($cell['lat'], $cell['lon']);
            if (!is_file($archive)) {
                continue;
            }

            $normals = $this->calculator->normals($this->readArchive($archive));
            foreach ($cell['units'] as $code) {
                foreach ($normals as $measure => $value) {
                    fputcsv($out, [$code, self::PERIOD, $measure, round($value, 3)], escape: '\\');
                }

                ++$written;
            }
        }

        fclose($out);
        rename($tmp, $path);

        return $written;
    }

    private function archivePath(float $lat, float $lon): string
    {
        // The variable list is part of the key: a different one is a different file.
        $variables = substr(sha1(implode(',', ClimateNormalsCalculator::DAILY_VARIABLES)), 0, 8);

        return \sprintf('%s/%s_%s_%s_%s_%s.json.gz', $this->archiveDir, $lat, $lon, self::START_DATE, self::END_DATE, $variables);
    }

    /**
     * @param array<mixed> $location the location's response, as received
     */
    private function archive(float $lat, float $lon, array $location): void
    {
        $path = $this->archivePath($lat, $lon);
        $encoded = gzencode(json_encode($location, \JSON_THROW_ON_ERROR), 9);
        if (false === $encoded) {
            throw new \RuntimeException('Cannot compress an Open-Meteo response.');
        }

        // Rename: a crash never leaves a truncated file that would pass for archived.
        file_put_contents($path.'.tmp', $encoded);
        rename($path.'.tmp', $path);
    }

    /**
     * @return array<string, list<mixed>> the `daily` block
     */
    private function readArchive(string $path): array
    {
        $json = gzdecode((string) file_get_contents($path));
        /** @var array{daily: array<string, list<mixed>>} $location */
        $location = json_decode(false === $json ? '' : $json, true, flags: \JSON_THROW_ON_ERROR);

        return $location['daily'];
    }

    private function recordCalls(int $locations): void
    {
        $ledger = $this->archiveDir.'/calls-ledger.csv';
        $isNew = !is_file($ledger);
        $handle = fopen($ledger, 'a') ?: throw new \RuntimeException('Cannot write the calls ledger.');
        if ($isNew) {
            fputcsv($handle, ['date', 'locations', 'calls'], escape: '\\');
        }

        fputcsv($handle, [new \DateTimeImmutable()->format('c'), $locations, round($locations * self::callsPerLocation())], escape: '\\');
        fclose($handle);
    }

    /**
     * A truncated or malformed body happens now and then on big responses;
     * every attempt is a paid request, so there are few.
     *
     * @param list<array{lat: float, lon: float}> $locations
     *
     * @return list<array<mixed>>
     */
    private function fetchWithRetry(array $locations): array
    {
        for ($attempt = 1;; ++$attempt) {
            try {
                return $this->fetchDaily($locations);
            } catch (\RuntimeException $e) {
                if ($attempt >= 2) {
                    throw $e;
                }

                $this->recordCalls(\count($locations));
            }
        }
    }

    /**
     * @param list<array{lat: float, lon: float}> $locations
     *
     * @return list<array<mixed>> the response of each location, in order
     */
    private function fetchDaily(array $locations): array
    {
        $query = [
            'latitude' => implode(',', array_column($locations, 'lat')),
            'longitude' => implode(',', array_column($locations, 'lon')),
            'start_date' => self::START_DATE,
            'end_date' => self::END_DATE,
            'daily' => implode(',', ClimateNormalsCalculator::DAILY_VARIABLES),
            'timezone' => 'auto',
        ];
        if ('' !== $this->apiKey) {
            $query['apikey'] = $this->apiKey;
        }

        try {
            /** @var array<mixed> $data */
            $data = $this->httpClient->request('GET', '' === $this->apiKey ? self::FREE_URL : self::CUSTOMER_URL, ['query' => $query, 'timeout' => 300])->toArray();
        } catch (ExceptionInterface $e) {
            // The exception message carries the URL, hence the key.
            throw new \RuntimeException(str_replace($this->apiKey, '***', $e->getMessage()), $e->getCode());
        }

        // A single location is answered as an object, several as a list.
        $results = isset($data['daily']) ? [$data] : $data;
        if (\count($results) !== \count($locations)) {
            throw new \RuntimeException(\sprintf('Open-Meteo answered %d locations for %d requested.', \count($results), \count($locations)));
        }

        $locationsData = [];
        foreach ($results as $result) {
            if (!\is_array($result) || !\is_array($result['daily'] ?? null)) {
                throw new \RuntimeException('Open-Meteo answered no daily data.');
            }

            $locationsData[] = $result;
        }

        return $locationsData;
    }
}
