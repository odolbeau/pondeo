<?php

declare(strict_types=1);

namespace App\Service\Import;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Refreshes `resources/geo/sites-seveso.csv` from Géorisques' "Établissements
 * Seveso - France métropolitaine" WFS layer (BRGM, data.gouv.fr). Sites on
 * their way out ("En fin d'exploitation") are left out: they are no longer a
 * hazard to live next to.
 */
final readonly class SevesoSiteSourceDownloader
{
    private const string WFS_URL = 'http://mapsref.brgm.fr/wxs/georisques/seveso';

    private const array CSV_COLUMNS = ['id', 'name', 'lat', 'lon', 'level', 'activity', 'address'];

    private const array LEVELS = [
        'Seveso seuil haut' => SevesoSiteImporter::LEVEL_HIGH,
        'Seveso seuil bas' => SevesoSiteImporter::LEVEL_LOW,
    ];

    private const string CLOSING_STATUS = "En fin d'exploitation";

    public function __construct(private HttpClientInterface $httpClient)
    {
    }

    /**
     * @param \Closure(string): void $progress
     *
     * @throws ExceptionInterface when the download fails
     */
    public function download(string $targetDir, \Closure $progress): void
    {
        $progress('Downloading sites-seveso.csv...');

        $response = $this->httpClient->request('GET', self::WFS_URL, ['query' => [
            'SERVICE' => 'WFS',
            'VERSION' => '2.0.0',
            'REQUEST' => 'GetFeature',
            'TYPENAMES' => 'ms:SEVESO_GE_FXX',
            'SRSNAME' => 'EPSG:4326',
            'OUTPUTFORMAT' => 'application/json; subtype=geojson; charset=utf-8',
        ]]);

        /** @var array{features?: list<array{properties: array<string, string>, geometry: array{coordinates: array{float, float}}}>} $collection */
        $collection = $response->toArray();

        $progress(\sprintf('  %d sites', $this->writeSites($collection['features'] ?? [], $targetDir.'/sites-seveso.csv')));
    }

    /**
     * @param list<array{properties: array<string, string>, geometry: array{coordinates: array{float, float}}}> $features
     */
    private function writeSites(array $features, string $targetPath): int
    {
        if ([] === $features) {
            throw new \RuntimeException('Unexpected response: no features.');
        }

        $rows = [];
        foreach ($features as $feature) {
            $properties = $feature['properties'];
            if (self::CLOSING_STATUS === $properties['status']) {
                continue;
            }

            $level = self::LEVELS[$properties['type']] ?? null;
            if (null === $level) {
                throw new \RuntimeException(\sprintf('Unexpected Seveso type "%s".', $properties['type']));
            }

            [$lon, $lat] = $feature['geometry']['coordinates'];

            $rows[$properties['identifier']] = [
                $properties['identifier'],
                trim($properties['name']),
                $lat,
                $lon,
                $level,
                $this->activityLabel($properties['activity']),
                $this->address($properties),
            ];
        }

        // Sorted so a refresh only diffs on real changes.
        ksort($rows);

        $out = fopen($targetPath, 'w') ?: throw new \RuntimeException("Cannot write {$targetPath}");
        fputcsv($out, self::CSV_COLUMNS, escape: '\\');
        foreach ($rows as $row) {
            fputcsv($out, $row, escape: '\\');
        }

        fclose($out);

        return \count($rows);
    }

    /** "20 - Industrie chimique" -> "Industrie chimique". */
    private function activityLabel(string $activity): string
    {
        return trim(preg_replace('/^\d+\s*-\s*/', '', $activity) ?? $activity);
    }

    /**
     * @param array<string, string> $properties
     */
    private function address(array $properties): string
    {
        $town = trim($properties['postalcode'].' '.$properties['city']);

        return implode(', ', array_filter([trim($properties['streetname']), $town]));
    }
}
