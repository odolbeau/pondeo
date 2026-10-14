<?php

declare(strict_types=1);

namespace App\Service\Import;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Downloads every feature of a layer of Géorisques' WFS service (BRGM), which
 * answers by pages. Streamed: the polygons of a whole layer would not fit in memory.
 */
final readonly class GeorisquesFeatureDownloader
{
    private const string WFS_URL = 'http://mapsref.brgm.fr/wxs/georisques/risques';

    private const int PAGE_SIZE = 500;

    public function __construct(private HttpClientInterface $httpClient)
    {
    }

    /**
     * @param string $sortBy a property that is unique, so the pages do not overlap
     *
     * @return \Generator<int, array{properties: array<string, string>, geometry: array{coordinates: array{float, float}}}>
     *
     * @throws ExceptionInterface when the download fails
     */
    public function download(string $layer, string $sortBy): \Generator
    {
        for ($start = 0;; $start += self::PAGE_SIZE) {
            $response = $this->httpClient->request('GET', self::WFS_URL, ['query' => [
                'SERVICE' => 'WFS',
                'VERSION' => '2.0.0',
                'REQUEST' => 'GetFeature',
                'TYPENAMES' => 'ms:'.$layer,
                'SRSNAME' => 'EPSG:4326',
                'OUTPUTFORMAT' => 'application/json; subtype=geojson; charset=utf-8',
                'SORTBY' => $sortBy,
                'COUNT' => self::PAGE_SIZE,
                'STARTINDEX' => $start,
            ]]);

            /** @var array{features?: list<array{properties: array<string, string>, geometry: array{coordinates: array{float, float}}}>} $page */
            $page = $response->toArray();
            $pageFeatures = $page['features'] ?? [];
            yield from $pageFeatures;

            if (\count($pageFeatures) < self::PAGE_SIZE) {
                return;
            }
        }
    }
}
