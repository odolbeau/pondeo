<?php

declare(strict_types=1);

namespace App\Service\Import;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Refreshes `resources/geo/revenus-filosofi.csv` from INSEE's Mélodi API:
 * the median standard of living and the poverty rate of each commune
 * (Filosofi, a single year).
 */
final readonly class IncomeSourceDownloader
{
    private const string API_URL = 'https://api.insee.fr/melodi/data/DS_FILOSOFI_CC';

    private const int YEAR = 2023;

    /** Value of the API's measure dimension, indexed by the measure written in the CSV. */
    private const array MEASURES = ['median' => 'MED_SL', 'poverty' => 'PR_MD60'];

    private const int THROTTLE_MICROSECONDS = 2_100_000;

    public function __construct(private HttpClientInterface $httpClient)
    {
    }

    /**
     * @param \Closure(string): void $progress called with a line per measure downloaded
     *
     * @throws ExceptionInterface when a request fails
     */
    public function download(string $targetDir, \Closure $progress): void
    {
        $targetPath = $targetDir.'/revenus-filosofi.csv';

        $handle = fopen($targetPath, 'w') ?: throw new \RuntimeException("Cannot write {$targetPath}");
        fputcsv($handle, ['code_insee', 'year', 'measure', 'value'], escape: '\\');

        foreach (self::MEASURES as $measure => $apiMeasure) {
            $progress(\sprintf('Downloading %s...', $measure));
            $progress(\sprintf('  %d communes', $this->downloadMeasure($measure, $apiMeasure, $handle)));
        }

        fclose($handle);
    }

    /**
     * @param resource $handle
     */
    private function downloadMeasure(string $measure, string $apiMeasure, $handle): int
    {
        $rowCount = 0;
        $page = 1;

        do {
            $response = $this->httpClient->request('GET', self::API_URL, [
                'query' => ['FILOSOFI_MEASURE' => $apiMeasure, 'TIME_PERIOD' => self::YEAR, 'page' => $page],
            ]);
            /** @var array{observations: list<array{dimensions: array{GEO: string}, measures: array{OBS_VALUE_NIVEAU: array{value?: float}}}>, paging: array{isLast?: bool}} $data */
            $data = $response->toArray();

            foreach ($data['observations'] as $observation) {
                $value = $observation['measures']['OBS_VALUE_NIVEAU']['value'] ?? null;
                if (null === $value || 1 !== preg_match('/^\d{4}-COM-(\w+)$/', $observation['dimensions']['GEO'], $matches)) {
                    continue; // Not a commune, or no figure (statistical secrecy).
                }

                fputcsv($handle, [$matches[1], self::YEAR, $measure, $value], escape: '\\');
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
}
