<?php

declare(strict_types=1);

namespace App\Service\Import;

use Doctrine\DBAL\Connection;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Refreshes `resources/geo/pyramide-ages.csv` from INSEE's Mélodi API: the
 * census population by sex and age of each unité urbaine, grouped here into
 * five-year brackets. INSEE publishes these figures for the unités urbaines
 * themselves, so nothing is summed from communes, but the API can't filter
 * several of them at once: it takes one request per unité urbaine.
 */
final readonly class AgePyramidSourceDownloader
{
    private const string API_URL = 'https://api.insee.fr/melodi/data/DS_RP_TD_POPULATION_AGESEX_PRINC';

    /** Width of an age bracket, and the start of the open-ended last one. */
    public const int BRACKET_SIZE = 5;

    public const int LAST_BRACKET_START = 95;

    // Same pause as the other downloads: the API caps requests per minute.
    private const int THROTTLE_MICROSECONDS = 2_100_000;

    private const int ATTEMPTS = 3;

    public function __construct(
        private HttpClientInterface $httpClient,
        private Connection $connection,
    ) {
    }

    /**
     * @param \Closure(string): void $progress called with a line every hundred unités urbaines
     *
     * @throws ExceptionInterface when a request keeps failing
     */
    public function download(string $targetDir, \Closure $progress): void
    {
        $targetPath = $targetDir.'/pyramide-ages.csv';

        /** @var list<string> $codes */
        $codes = $this->connection->fetchFirstColumn('SELECT insee_code FROM unite_urbaine ORDER BY insee_code');

        $handle = fopen($targetPath, 'w') ?: throw new \RuntimeException("Cannot write {$targetPath}");
        fputcsv($handle, ['code_insee', 'year', 'sex', 'age_from', 'count'], escape: '\\');

        foreach ($codes as $i => $code) {
            if (0 === $i % 100) {
                $progress(\sprintf('Downloading unités urbaines %d/%d...', $i + 1, \count($codes)));
            }

            $this->downloadUniteUrbaine($code, $handle);
            usleep(self::THROTTLE_MICROSECONDS);
        }

        fclose($handle);
    }

    /**
     * @param resource $handle
     */
    private function downloadUniteUrbaine(string $code, $handle): void
    {
        $data = $this->fetch($code);

        /** @var array<int, array<string, array<int, float>>> $brackets indexed by year, sex, then bracket start */
        $brackets = [];
        foreach ($data['observations'] as $observation) {
            $value = $observation['measures']['OBS_VALUE_NIVEAU']['value'] ?? null;
            $sex = $observation['dimensions']['SEX'];
            $start = $this->bracketStart($observation['dimensions']['AGE']);
            if (null === $value || null === $start || !\in_array($sex, ['F', 'M'], true)) {
                continue; // A total, a coarser bracket, or no figure.
            }

            $year = (int) $observation['dimensions']['TIME_PERIOD'];
            $brackets[$year][$sex][$start] = ($brackets[$year][$sex][$start] ?? 0.0) + $value;
        }

        foreach ($brackets as $year => $bySex) {
            foreach ($bySex as $sex => $byStart) {
                foreach ($byStart as $start => $count) {
                    fputcsv($handle, [$code, $year, $sex, $start, (int) round($count)], escape: '\\');
                }
            }
        }
    }

    /**
     * @return array{observations: list<array{dimensions: array{SEX: string, AGE: string, TIME_PERIOD: string}, measures: array{OBS_VALUE_NIVEAU: array{value?: float}}}>}
     *
     * @throws ExceptionInterface when the request keeps failing
     */
    private function fetch(string $code): array
    {
        for ($attempt = 1;; ++$attempt) {
            try {
                /** @var array{observations: list<array{dimensions: array{SEX: string, AGE: string, TIME_PERIOD: string}, measures: array{OBS_VALUE_NIVEAU: array{value?: float}}}>} $data */
                $data = $this->httpClient->request('GET', self::API_URL, ['query' => ['GEO' => '2026-UU2020-'.$code]])->toArray();

                return $data;
            } catch (ExceptionInterface $e) {
                if (self::ATTEMPTS === $attempt) {
                    throw $e;
                }

                sleep(10 * $attempt);
            }
        }
    }

    /**
     * @param string $age e.g. "Y37" or "Y_GE100"; null for the total
     */
    private function bracketStart(string $age): ?int
    {
        if ('Y_GE100' === $age) {
            return self::LAST_BRACKET_START;
        }

        if (1 !== preg_match('/^Y(\d{1,2})$/', $age, $matches)) {
            return null;
        }

        return min(intdiv((int) $matches[1], self::BRACKET_SIZE) * self::BRACKET_SIZE, self::LAST_BRACKET_START);
    }
}
