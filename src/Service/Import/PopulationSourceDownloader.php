<?php

declare(strict_types=1);

namespace App\Service\Import;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Refreshes `resources/geo/population-historique.csv` from INSEE's Mélodi
 * API: the annual municipal population, one request per page and year.
 */
final readonly class PopulationSourceDownloader
{
    private const string API_URL = 'https://api.insee.fr/melodi/data/DS_POPULATIONS_HISTORIQUES';

    // Annual estimates only exist from 2006 onward (see plan: older census
    // campaigns are sparser/less comparable, out of scope for now).
    private const int FIRST_YEAR = 2006;

    private const int LAST_YEAR = 2023;

    // The API isn't documented as unlimited — a small pause between the
    // ~72 requests this makes keeps us well under any reasonable rate limit
    // for what is an occasional, manual refresh.
    private const int THROTTLE_MICROSECONDS = 2_100_000;

    public function __construct(private HttpClientInterface $httpClient)
    {
    }

    /**
     * @param \Closure(string): void $progress called with a line per year downloaded
     *
     * @throws ExceptionInterface when a request fails
     */
    public function download(string $targetDir, \Closure $progress): void
    {
        $targetPath = $targetDir.'/population-historique.csv';

        $handle = fopen($targetPath, 'w') ?: throw new \RuntimeException("Cannot write {$targetPath}");
        fputcsv($handle, ['code_insee', 'year', 'population'], escape: '\\');

        for ($year = self::FIRST_YEAR; $year <= self::LAST_YEAR; ++$year) {
            $progress(\sprintf('Downloading %d...', $year));
            $progress(\sprintf('  %d communes', $this->downloadYear($year, $handle)));
        }

        fclose($handle);
    }

    /**
     * @param resource $handle
     */
    private function downloadYear(int $year, $handle): int
    {
        $rowCount = 0;
        $page = 1;

        do {
            $response = $this->httpClient->request('GET', self::API_URL, [
                'query' => ['POPREF_MEASURE' => 'PMUN', 'TIME_PERIOD' => $year, 'page' => $page],
            ]);
            /** @var array{observations: list<array{dimensions: array{GEO: string}, measures: array{OBS_VALUE_NIVEAU: array{value: float}}}>, paging: array{isLast?: bool}} $data */
            $data = $response->toArray();

            foreach ($data['observations'] as $observation) {
                $codeInsee = $this->extractCommuneCode($observation['dimensions']['GEO']);
                if (null === $codeInsee) {
                    continue; // Not a commune-level observation — skip.
                }

                fputcsv($handle, [$codeInsee, $year, (int) $observation['measures']['OBS_VALUE_NIVEAU']['value']], escape: '\\');
                ++$rowCount;
            }

            $isLast = $data['paging']['isLast'] ?? false;
            ++$page;

            if (!$isLast) {
                usleep(self::THROTTLE_MICROSECONDS);
            }
        } while (!$isLast);

        return $rowCount;
    }

    /**
     * @param string $geo e.g. "2025-COM-01001"
     */
    private function extractCommuneCode(string $geo): ?string
    {
        if (1 !== preg_match('/^\d{4}-COM-(\w+)$/', $geo, $matches)) {
            return null;
        }

        return $matches[1];
    }
}
