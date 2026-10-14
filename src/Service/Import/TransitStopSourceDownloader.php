<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Enum\TransitStopMode;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Refreshes `resources/geo/arrets-tram-metro.csv` from the "équipement de
 * transport" layer of IGN's BD TOPO (Géoplateforme WFS, Licence Ouverte): the
 * stations of the tram and metro lines in service, one row per station. The
 * layer stores each as a few-metre polygon; the file keeps its centre.
 */
final readonly class TransitStopSourceDownloader
{
    private const string WFS_URL = 'https://data.geopf.fr/wfs/ows';

    private const int PAGE_SIZE = 5000;

    /**
     * Two same-named stops of a mode and commune closer than this are the two
     * platforms of one station (one per direction), not two stations.
     */
    private const int SAME_STATION_METRES = 250;

    private const float METRES_PER_DEGREE = 111_320.0;

    private const array CSV_COLUMNS = ['id', 'name', 'mode', 'lat', 'lon', 'code_commune'];

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
        $progress('Downloading arrets-tram-metro.csv...');

        $targetPath = $targetDir.'/arrets-tram-metro.csv';
        $out = fopen($targetPath, 'w') ?: throw new \RuntimeException("Cannot write {$targetPath}");
        fputcsv($out, self::CSV_COLUMNS, escape: '\\');

        foreach (TransitStopMode::cases() as $mode) {
            $stations = $this->mergePlatforms($this->fetchStops($mode));
            $progress(\sprintf('  %d × %s', \count($stations), $mode->sourceNature()));

            foreach ($stations as $station) {
                fputcsv($out, [$station['id'], $station['name'], $mode->value, \sprintf('%.7F', $station['lat']), \sprintf('%.7F', $station['lon']), $station['commune']], escape: '\\');
            }
        }

        fclose($out);
    }

    /**
     * @return list<array{id: string, name: string, lat: float, lon: float, commune: string}>
     *
     * @throws ExceptionInterface
     */
    private function fetchStops(TransitStopMode $mode): array
    {
        $stops = [];
        for ($start = 0;; $start += self::PAGE_SIZE) {
            $page = $this->httpClient->request('GET', self::WFS_URL, ['query' => [
                'service' => 'WFS',
                'version' => '2.0.0',
                'request' => 'GetFeature',
                'typeNames' => 'BDTOPO_V3:equipement_de_transport',
                'outputFormat' => 'application/json',
                'propertyName' => 'cleabs,toponyme,insee_commune,geometrie',
                // A stable order, or the pages overlap and skip rows.
                'sortBy' => 'cleabs',
                'cql_filter' => \sprintf("nature = '%s' AND etat_de_l_objet = 'En service'", $mode->sourceNature()),
                'count' => self::PAGE_SIZE,
                'startIndex' => $start,
            ]])->toArray();

            /** @var list<array{geometry: array{coordinates: array<mixed>}|null, properties: array{cleabs: string, toponyme: string|null, insee_commune: string|null}}> $features */
            $features = $page['features'];
            foreach ($features as $feature) {
                $name = trim($feature['properties']['toponyme'] ?? '');
                $centre = null === $feature['geometry'] ? null : $this->centre($feature['geometry']['coordinates']);
                // A stop nobody can name or place tells nothing on a detail page.
                if ('' === $name || null === $centre) {
                    continue;
                }

                $stops[] = [
                    'id' => $feature['properties']['cleabs'],
                    'name' => $name,
                    'lat' => $centre[1],
                    'lon' => $centre[0],
                    'commune' => $feature['properties']['insee_commune'] ?? '',
                ];
            }

            if (\count($features) < self::PAGE_SIZE) {
                return $stops;
            }
        }
    }

    /**
     * Folds the platforms of one station into a single row: the id of the
     * first one, at the mean of their positions.
     *
     * @param list<array{id: string, name: string, lat: float, lon: float, commune: string}> $stops
     *
     * @return list<array{id: string, name: string, lat: float, lon: float, commune: string}>
     */
    private function mergePlatforms(array $stops): array
    {
        /** @var list<array{id: string, name: string, lat: float, lon: float, commune: string, platforms: int}> $stations */
        $stations = [];
        foreach ($stops as $stop) {
            foreach ($stations as $i => $station) {
                if (mb_strtolower($station['name']) !== mb_strtolower($stop['name']) || $station['commune'] !== $stop['commune']) {
                    continue;
                }

                if ($this->distanceInMetres($station, $stop) > self::SAME_STATION_METRES) {
                    continue;
                }

                $n = $station['platforms'];
                $stations[$i]['lat'] = ($station['lat'] * $n + $stop['lat']) / ($n + 1);
                $stations[$i]['lon'] = ($station['lon'] * $n + $stop['lon']) / ($n + 1);
                $stations[$i]['platforms'] = $n + 1;

                continue 2;
            }

            $stations[] = $stop + ['platforms' => 1];
        }

        return array_map(static fn (array $station): array => [
            'id' => $station['id'],
            'name' => $station['name'],
            'lat' => $station['lat'],
            'lon' => $station['lon'],
            'commune' => $station['commune'],
        ], $stations);
    }

    /**
     * @param array{lat: float, lon: float} $a
     * @param array{lat: float, lon: float} $b
     */
    private function distanceInMetres(array $a, array $b): float
    {
        // Flat-earth approximation: exact enough at the scale of a station.
        $dLat = ($a['lat'] - $b['lat']) * self::METRES_PER_DEGREE;
        $dLon = ($a['lon'] - $b['lon']) * self::METRES_PER_DEGREE * cos(deg2rad(($a['lat'] + $b['lat']) / 2));

        return hypot($dLat, $dLon);
    }

    /**
     * Mean of every vertex of a Point, Polygon or MultiPolygon: the closing
     * vertex repeats the first, which only shifts the mean of a few-metre
     * polygon by less than a metre.
     *
     * @param array<mixed> $coordinates
     *
     * @return array{float, float}|null longitude, latitude
     */
    private function centre(array $coordinates): ?array
    {
        $vertices = $this->vertices($coordinates);
        if ([] === $vertices) {
            return null;
        }

        return [
            array_sum(array_column($vertices, 0)) / \count($vertices),
            array_sum(array_column($vertices, 1)) / \count($vertices),
        ];
    }

    /**
     * @param array<mixed> $node a position, or any nesting of lists of positions
     *
     * @return list<array{float, float}>
     */
    private function vertices(array $node): array
    {
        if (isset($node[0], $node[1]) && is_numeric($node[0]) && is_numeric($node[1])) {
            return [[(float) $node[0], (float) $node[1]]];
        }

        $vertices = [];
        foreach ($node as $child) {
            if (\is_array($child)) {
                array_push($vertices, ...$this->vertices($child));
            }
        }

        return $vertices;
    }
}
