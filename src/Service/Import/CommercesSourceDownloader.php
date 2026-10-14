<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Enum\ShopType;
use Doctrine\DBAL\Connection;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Refreshes `resources/geo/commerces-bpe.csv` from INSEE's Mélodi API: the
 * number of shops per unité urbaine, year and shop type of the "BPE en
 * évolution" series. Only the unités urbaine already imported are kept:
 * import the zones first.
 */
final readonly class CommercesSourceDownloader
{
    private const string API_URL = 'https://api.insee.fr/melodi/data/DS_BPE_EVOLUTION';

    // Same courtesy pause as the population download.
    private const int THROTTLE_MICROSECONDS = 2_100_000;

    public function __construct(
        private HttpClientInterface $httpClient,
        private Connection $connection,
        private int $throttleMicroseconds = self::THROTTLE_MICROSECONDS,
    ) {
    }

    /**
     * @param \Closure(string): void $progress called with a line per shop type downloaded
     *
     * @throws ExceptionInterface when a request fails
     */
    public function download(string $targetDir, \Closure $progress): void
    {
        /** @var list<string> $codes */
        $codes = $this->connection->fetchFirstColumn('SELECT insee_code FROM unite_urbaine');
        $uniteUrbaineCodes = array_flip($codes);

        $targetPath = $targetDir.'/commerces-bpe.csv';
        $handle = fopen($targetPath, 'w') ?: throw new \RuntimeException("Cannot write {$targetPath}");
        fputcsv($handle, ['code_unite_urbaine', 'year', 'type', 'count'], escape: '\\');

        // One request series per type, all years at once: the years the
        // dataset holds are not hardcoded, and a dataset-wide request would
        // return the 150-odd other equipment types too.
        foreach (ShopType::cases() as $type) {
            $progress(\sprintf('Downloading %s (%s)...', $type->value, $type->label()));

            $page = 1;
            do {
                $response = $this->httpClient->request('GET', self::API_URL, [
                    'query' => ['FACILITY_TYPE' => $type->value, 'BPE_MEASURE' => 'FACILITIES', 'page' => $page],
                ]);
                /** @var array{observations: list<array{dimensions: array{GEO: string, TIME_PERIOD: string}, measures: array{OBS_VALUE_NIVEAU: array{value?: float}}}>, paging: array{isLast?: bool}} $data */
                $data = $response->toArray();

                foreach ($data['observations'] as $observation) {
                    $value = $observation['measures']['OBS_VALUE_NIVEAU']['value'] ?? null;
                    if (null === $value || 1 !== preg_match('/^\d{4}-UU2020-(\w+)$/', $observation['dimensions']['GEO'], $matches) || !isset($uniteUrbaineCodes[$matches[1]])) {
                        continue; // Another zoning, an unité urbaine out of scope, or no figure (statistical secrecy).
                    }

                    fputcsv($handle, [$matches[1], $observation['dimensions']['TIME_PERIOD'], $type->value, (int) $value], escape: '\\');
                }

                $isLast = $data['paging']['isLast'] ?? false;
                ++$page;

                usleep($this->throttleMicroseconds);
            } while (!$isLast);
        }

        fclose($handle);
    }
}
