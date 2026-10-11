<?php

declare(strict_types=1);

namespace App\Service\Import;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Refreshes `resources/geo/emploi-recensement.csv` from INSEE's Mélodi API:
 * the census population aged 15-64 by employment status, per commune.
 */
final readonly class EmploymentSourceDownloader
{
    private const string API_URL = 'https://api.insee.fr/melodi/data/DS_RP_EMPLOI_LR_PRINC';

    /** Census years available in the dataset (rolling 5-year surveys, not annual). */
    private const array YEARS = [2012, 2017, 2023];

    /** Value of the API's employment status dimension, indexed by the status written in the CSV. */
    private const array STATUSES = ['actifs' => '1T2', 'chomeurs' => '2', 'population' => '_T'];

    private const int THROTTLE_MICROSECONDS = 2_100_000;

    public function __construct(private HttpClientInterface $httpClient)
    {
    }

    /**
     * @param \Closure(string): void $progress called with a line per year and status downloaded
     *
     * @throws ExceptionInterface when a request fails
     */
    public function download(string $targetDir, \Closure $progress): void
    {
        $targetPath = $targetDir.'/emploi-recensement.csv';

        $handle = fopen($targetPath, 'w') ?: throw new \RuntimeException("Cannot write {$targetPath}");
        fputcsv($handle, ['code_insee', 'year', 'status', 'count'], escape: '\\');

        foreach (self::YEARS as $year) {
            foreach (self::STATUSES as $status => $apiStatus) {
                $progress(\sprintf('Downloading %d (%s)...', $year, $status));
                $progress(\sprintf('  %d communes', $this->downloadPart($year, $status, $apiStatus, $handle)));
            }
        }

        fclose($handle);
    }

    /**
     * @param resource $handle
     */
    private function downloadPart(int $year, string $status, string $apiStatus, $handle): int
    {
        $rowCount = 0;
        $page = 1;

        do {
            $response = $this->httpClient->request('GET', self::API_URL, [
                'query' => [
                    'SEX' => '_T',
                    'AGE' => 'Y15T64',
                    'EDUC' => '_T',
                    'EMPSTA_ENQ' => $apiStatus,
                    'TIME_PERIOD' => $year,
                    'page' => $page,
                ],
            ]);
            /** @var array{observations: list<array{dimensions: array{GEO: string}, measures: array{OBS_VALUE_NIVEAU: array{value?: float}}}>, paging: array{isLast?: bool}} $data */
            $data = $response->toArray();

            foreach ($data['observations'] as $observation) {
                $value = $observation['measures']['OBS_VALUE_NIVEAU']['value'] ?? null;
                if (null === $value || 1 !== preg_match('/^\d{4}-COM-(\w+)$/', $observation['dimensions']['GEO'], $matches)) {
                    continue; // Not a commune, or no figure (statistical secrecy).
                }

                fputcsv($handle, [$matches[1], $year, $status, round($value, 2)], escape: '\\');
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
