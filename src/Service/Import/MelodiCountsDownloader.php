<?php

declare(strict_types=1);

namespace App\Service\Import;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Downloads commune-level counts of an INSEE Mélodi dataset into a long CSV
 * (`code_insee,year,status,count`), one status per set of query filters. The
 * years are whatever the dataset holds for those filters.
 */
final readonly class MelodiCountsDownloader
{
    private const string API_URL = 'https://api.insee.fr/melodi/data/';

    // The API isn't documented as unlimited: a pause between pages keeps an
    // occasional, manual refresh well under any reasonable rate limit.
    private const int THROTTLE_MICROSECONDS = 2_100_000;

    public function __construct(private HttpClientInterface $httpClient)
    {
    }

    /**
     * @param array<string, array<string, string>> $statuses query filters indexed by the status written in the CSV
     * @param \Closure(string): void               $progress called with a line per status downloaded
     *
     * @throws ExceptionInterface when a request fails
     */
    public function download(string $dataset, string $targetPath, array $statuses, \Closure $progress): void
    {
        $handle = fopen($targetPath, 'w') ?: throw new \RuntimeException("Cannot write {$targetPath}");
        fputcsv($handle, ['code_insee', 'year', 'status', 'count'], escape: '\\');

        foreach ($statuses as $status => $filters) {
            $progress(\sprintf('Downloading %s...', $status));
            $progress(\sprintf('  %d values', $this->downloadStatus($dataset, $status, $filters, $handle)));
        }

        fclose($handle);
    }

    /**
     * @param array<string, string> $filters
     * @param resource              $handle
     */
    private function downloadStatus(string $dataset, string $status, array $filters, $handle): int
    {
        $rowCount = 0;
        $page = 1;

        do {
            $response = $this->httpClient->request('GET', self::API_URL.$dataset, ['query' => $filters + ['page' => $page]]);
            /** @var array{observations: list<array{dimensions: array{GEO: string, TIME_PERIOD: string}, measures: array{OBS_VALUE_NIVEAU: array{value?: float}}}>, paging: array{isLast?: bool}} $data */
            $data = $response->toArray();

            foreach ($data['observations'] as $observation) {
                $value = $observation['measures']['OBS_VALUE_NIVEAU']['value'] ?? null;
                if (null === $value || 1 !== preg_match('/^\d{4}-COM-(\w+)$/', $observation['dimensions']['GEO'], $matches)) {
                    continue; // Not a commune, or no figure (statistical secrecy).
                }

                fputcsv($handle, [$matches[1], $observation['dimensions']['TIME_PERIOD'], $status, round($value, 2)], escape: '\\');
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
