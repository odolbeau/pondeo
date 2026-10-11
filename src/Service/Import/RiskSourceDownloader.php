<?php

declare(strict_types=1);

namespace App\Service\Import;

use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Refreshes `resources/geo/risques-communes.csv` from the Géorisques API:
 * per commune, the recorded hazards turned into the measures below. Slow
 * (about five calls per commune) and resumable: communes already written for
 * the current year are skipped, so an interrupted run picks up where it
 * stopped. A commune the API has no answer for gets no row for that measure,
 * rather than a made-up zero.
 */
final readonly class RiskSourceDownloader
{
    public const string FILE = 'risques-communes.csv';

    public const string MEASURE_CATNAT = 'catnat';

    public const string MEASURE_FLOOD = 'inondation';

    public const string MEASURE_FIRE = 'feu_foret';

    public const string MEASURE_SEISMIC = 'zone_sismique';

    public const string MEASURE_RADON = 'radon_fort';

    public const string MEASURE_CLAY = 'argiles';

    public const int CATNAT_YEARS = 20;

    private const int THROTTLE_MICROSECONDS = 150_000;

    private const string API_URL = 'https://georisques.gouv.fr/api/v1';

    // GASPAR risk numbers in the commune's risk list.
    private const string RISK_FLOOD = '11';

    private const string RISK_FIRE = '16';

    private const string RADON_HIGH_CLASS = '3';

    private const array CSV_COLUMNS = ['code_insee', 'year', 'measure', 'value', 'population'];

    private HttpClientInterface $httpClient;

    public function __construct(
        HttpClientInterface $httpClient,
        private Connection $connection,
        private ClockInterface $clock,
        private CsvReader $csvReader,
        private int $throttleMicroseconds = self::THROTTLE_MICROSECONDS,
    ) {
        // The API answers 429/5xx under load: back off rather than fail a run of hours.
        $this->httpClient = new RetryableHttpClient($httpClient, maxRetries: 6);
    }

    /**
     * @param \Closure(string): void $progress
     *
     * @return int number of communes fetched during this run
     */
    public function download(string $targetDir, \Closure $progress): int
    {
        $year = (int) $this->clock->now()->format('Y');
        $path = $targetDir.'/'.self::FILE;

        $done = $this->resume($path, $year);
        $points = $this->loadClayPoints();

        // Append mode reports position 0 even on an existing file: look beforehand.
        $isNew = !is_file($path);
        $out = fopen($path, 'a') ?: throw new \RuntimeException('Cannot write '.self::FILE);
        if ($isNew) {
            fputcsv($out, self::CSV_COLUMNS, escape: '\\');
        }

        $communes = iterator_to_array($this->csvReader->read($targetDir.'/communes-unites-urbaines.csv'), false);
        $fetched = 0;
        foreach ($communes as $i => $commune) {
            $code = $commune['code_insee'];
            if (isset($done[$code])) {
                continue;
            }

            foreach ($this->fetchRows($code, $points[$code] ?? null) as $measure => $value) {
                fputcsv($out, [$code, $year, $measure, $value, $commune['population']], escape: '\\');
            }

            ++$fetched;
            if (0 === $fetched % 100) {
                fflush($out);
                $progress(\sprintf('  %d/%d communes', $i + 1, \count($communes)));
            }

            usleep($this->throttleMicroseconds);
        }

        fclose($out);

        return $fetched;
    }

    /**
     * @param array{float, float}|null $clayPoint longitude, latitude where the clay exposure is sampled
     *
     * @return array<string, int> value per measure, for the measures the API answered
     */
    public function fetchRows(string $codeInsee, ?array $clayPoint = null): array
    {
        // Every request goes out before the first answer is read.
        $catnat = $this->request('/gaspar/catnat', ['code_insee' => $codeInsee, 'page_size' => 1000]);
        $risks = $this->request('/gaspar/risques', ['code_insee' => $codeInsee]);
        $seismic = $this->request('/zonage_sismique', ['code_insee' => $codeInsee]);
        $radon = $this->request('/radon', ['code_insee' => $codeInsee]);
        $clay = null === $clayPoint ? null : $this->request('/rga', ['latlon' => $clayPoint[0].','.$clayPoint[1]]);

        $rows = [self::MEASURE_CATNAT => $this->countRecentDecrees($codeInsee, $catnat)];

        /** @var array{data?: list<array{risques_detail?: list<array{num_risque: string}>}>} $risksBody */
        $risksBody = $risks->toArray();
        if ([] !== ($risksBody['data'] ?? [])) {
            $listed = array_column($risksBody['data'][0]['risques_detail'] ?? [], 'num_risque');
            $rows[self::MEASURE_FLOOD] = \in_array(self::RISK_FLOOD, $listed, true) ? 100 : 0;
            $rows[self::MEASURE_FIRE] = \in_array(self::RISK_FIRE, $listed, true) ? 100 : 0;
        }

        /** @var array{data?: list<array{code_zone: string}>} $seismicBody */
        $seismicBody = $seismic->toArray();
        if ([] !== ($seismicBody['data'] ?? [])) {
            $rows[self::MEASURE_SEISMIC] = (int) $seismicBody['data'][0]['code_zone'];
        }

        /** @var array{data?: list<array{classe_potentiel: string}>} $radonBody */
        $radonBody = $radon->toArray();
        if ([] !== ($radonBody['data'] ?? [])) {
            $rows[self::MEASURE_RADON] = self::RADON_HIGH_CLASS === $radonBody['data'][0]['classe_potentiel'] ? 100 : 0;
        }

        // An empty body means the point is outside any mapped zone.
        if (null !== $clay && '' !== $clay->getContent()) {
            /** @var array{codeExposition?: string} $clayBody */
            $clayBody = $clay->toArray();
            if (isset($clayBody['codeExposition'])) {
                $rows[self::MEASURE_CLAY] = (int) $clayBody['codeExposition'];
            }
        }

        return $rows;
    }

    /**
     * @param array<string, scalar> $query
     */
    private function request(string $path, array $query): ResponseInterface
    {
        return $this->httpClient->request('GET', self::API_URL.$path, ['query' => $query]);
    }

    private function countRecentDecrees(string $codeInsee, ResponseInterface $response): int
    {
        $cutoff = $this->clock->now()->modify('-'.self::CATNAT_YEARS.' years')->setTime(0, 0);

        $count = 0;
        $page = 1;
        while (true) {
            /** @var array{total_pages?: int, data?: list<array{date_publication_jo?: string|null}>} $body */
            $body = $response->toArray();
            foreach ($body['data'] ?? [] as $decree) {
                $date = \DateTimeImmutable::createFromFormat('!d/m/Y', $decree['date_publication_jo'] ?? '');
                if (false !== $date && $date >= $cutoff) {
                    ++$count;
                }
            }

            if ($page >= ($body['total_pages'] ?? 1)) {
                return $count;
            }

            ++$page;
            $response = $this->request('/gaspar/catnat', ['code_insee' => $codeInsee, 'page_size' => 1000, 'page' => $page]);
        }
    }

    /**
     * Communes already fetched this year; rows of another year are dropped.
     *
     * @return array<string, true>
     */
    private function resume(string $path, int $year): array
    {
        if (!is_file($path)) {
            return [];
        }

        $kept = [];
        $done = [];
        foreach ($this->csvReader->read($path) as $row) {
            if ((int) $row['year'] === $year) {
                $kept[] = $row;
                $done[$row['code_insee']] = true;
            }
        }

        $out = fopen($path, 'w') ?: throw new \RuntimeException('Cannot write '.self::FILE);
        fputcsv($out, self::CSV_COLUMNS, escape: '\\');
        foreach ($kept as $row) {
            fputcsv($out, array_map(static fn (string $column): string => $row[$column], self::CSV_COLUMNS), escape: '\\');
        }

        fclose($out);

        return $done;
    }

    /**
     * The clay exposure map is only queryable at a point: one inside each commune.
     *
     * @return array<string, array{float, float}>
     */
    private function loadClayPoints(): array
    {
        /** @var list<array{insee_code: string, lon: float|string, lat: float|string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT insee_code, ST_X(ST_PointOnSurface(boundary::geometry)) AS lon, ST_Y(ST_PointOnSurface(boundary::geometry)) AS lat FROM commune WHERE boundary IS NOT NULL',
        );

        $points = [];
        foreach ($rows as $row) {
            $points[$row['insee_code']] = [round((float) $row['lon'], 5), round((float) $row['lat'], 5)];
        }

        return $points;
    }
}
