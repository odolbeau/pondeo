<?php

declare(strict_types=1);

namespace App\Service\Import;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Refreshes `resources/geo/commerces-bpe.csv` from INSEE's Mélodi API: the
 * number of shops per commune and sub-domain (large stores, food, non-food)
 * of the Base permanente des équipements.
 */
final readonly class CommercesSourceDownloader
{
    private const string API_URL = 'https://api.insee.fr/melodi/data/DS_BPE';

    // Same courtesy pause as the population download.
    private const int THROTTLE_MICROSECONDS = 2_100_000;

    public function __construct(private HttpClientInterface $httpClient)
    {
    }

    /**
     * @param \Closure(string): void $progress called with a line per page downloaded
     *
     * @throws ExceptionInterface when a request fails
     */
    public function download(string $targetDir, \Closure $progress): void
    {
        $targetPath = $targetDir.'/commerces-bpe.csv';

        $handle = fopen($targetPath, 'w') ?: throw new \RuntimeException("Cannot write {$targetPath}");
        fputcsv($handle, ['code_insee', 'year', 'sub_domain', 'count'], escape: '\\');

        $page = 1;
        do {
            $progress(\sprintf('Downloading page %d...', $page));

            // Type `_T` is the API's own total per sub-domain, so no per-type row is fetched.
            $response = $this->httpClient->request('GET', self::API_URL, [
                'query' => ['FACILITY_DOM' => 'B', 'FACILITY_TYPE' => '_T', 'BPE_MEASURE' => 'FACILITIES', 'page' => $page],
            ]);
            /** @var array{observations: list<array{dimensions: array{GEO: string, FACILITY_SDOM: string, TIME_PERIOD: string}, measures: array{OBS_VALUE_NIVEAU: array{value: float}}}>, paging: array{isLast?: bool}} $data */
            $data = $response->toArray();

            foreach ($data['observations'] as $observation) {
                $dimensions = $observation['dimensions'];
                if (1 !== preg_match('/^\d{4}-COM-(\w+)$/', $dimensions['GEO'], $matches)) {
                    continue; // Not a commune-level observation — skip.
                }

                if (!\in_array($dimensions['FACILITY_SDOM'], CommercesImporter::SUB_DOMAINS, true)) {
                    continue; // The domain total, derived on import.
                }

                fputcsv($handle, [$matches[1], $dimensions['TIME_PERIOD'], $dimensions['FACILITY_SDOM'], (int) $observation['measures']['OBS_VALUE_NIVEAU']['value']], escape: '\\');
            }

            $isLast = $data['paging']['isLast'] ?? false;
            ++$page;

            if (!$isLast) {
                usleep(self::THROTTLE_MICROSECONDS);
            }
        } while (!$isLast);

        fclose($handle);
    }
}
