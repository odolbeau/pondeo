<?php

declare(strict_types=1);

namespace App\Service\Import;

use Doctrine\DBAL\Connection;

/**
 * Computes `resources/geo/gares-acces-voiture.csv`: for each unité urbaine,
 * its few closest railway stations with the car route from the unit's
 * centroid (see DrivingRouteClient), fastest first. Stations inside the unit
 * are listed as 0 m / 0 s, with no call. Otherwise the CANDIDATES closest
 * stations as the crow flies are routed: the closest in a straight line is
 * not always the quickest by road. A station with no route is left out.
 */
final readonly class StationAccessSourceDownloader
{
    public const string FILE = 'gares-acces-voiture.csv';

    public const int CANDIDATES = 3;

    private const array CSV_COLUMNS = ['unite_urbaine_code', 'position', 'station_id', 'distance_m', 'duration_s', 'period'];

    private const int THROTTLE_MICROSECONDS = 500_000;

    public function __construct(
        private Connection $connection,
        private DrivingRouteClient $routeClient,
    ) {
    }

    /**
     * @param \Closure(string): void $progress
     *
     * @return int number of units with at least one station written
     */
    public function download(string $targetDir, \Closure $progress): int
    {
        $period = (int) new \DateTimeImmutable()->format('Y');

        /** @var list<array{id: string, insee_code: string, lon: float|string, lat: float|string}> $units */
        $units = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT uu.id, uu.insee_code, ST_X(uu.centroid::geometry) AS lon, ST_Y(uu.centroid::geometry) AS lat
                FROM unite_urbaine uu
                WHERE uu.centroid IS NOT NULL
                ORDER BY uu.insee_code
                SQL,
        );

        $out = fopen($targetDir.'/'.self::FILE, 'w') ?: throw new \RuntimeException('Cannot write '.self::FILE);
        fputcsv($out, self::CSV_COLUMNS, escape: '\\');

        $written = 0;
        foreach ($units as $i => $unit) {
            $routes = $this->findRoutes($unit['id'], (float) $unit['lon'], (float) $unit['lat']);
            if ([] === $routes) {
                $progress(\sprintf('  no route for unit %s', $unit['insee_code']));

                continue;
            }

            foreach ($routes as $position => $route) {
                fputcsv($out, [$unit['insee_code'], $position + 1, $route['station'], (int) round($route['distance']), (int) round($route['duration']), $period], escape: '\\');
            }

            ++$written;

            if (0 === ($i + 1) % 100) {
                $progress(\sprintf('  %d/%d units', $i + 1, \count($units)));
            }
        }

        fclose($out);

        return $written;
    }

    /**
     * @return list<array{station: string, distance: float, duration: float}> fastest first
     */
    private function findRoutes(string $uniteUrbaineId, float $lon, float $lat): array
    {
        /** @var list<string> $inside */
        $inside = $this->connection->fetchFirstColumn(
            <<<'SQL'
                SELECT p.external_id
                FROM poi p
                INNER JOIN poi_category pc ON pc.id = p.category_id AND pc.code = :category
                WHERE p.unite_urbaine_id = :id
                ORDER BY p.location <-> ST_SetSRID(ST_MakePoint(:lon, :lat), 4326)::geography
                LIMIT :limit
                SQL,
            ['category' => StationImporter::CATEGORY_CODE, 'id' => $uniteUrbaineId, 'lon' => $lon, 'lat' => $lat, 'limit' => self::CANDIDATES],
        );
        if ([] !== $inside) {
            return array_map(static fn (string $station): array => ['station' => $station, 'distance' => 0.0, 'duration' => 0.0], $inside);
        }

        /** @var list<array{external_id: string, lon: float|string, lat: float|string}> $candidates */
        $candidates = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT p.external_id, ST_X(p.location::geometry) AS lon, ST_Y(p.location::geometry) AS lat
                FROM poi p
                INNER JOIN poi_category pc ON pc.id = p.category_id AND pc.code = :category
                ORDER BY p.location <-> ST_SetSRID(ST_MakePoint(:lon, :lat), 4326)::geography
                LIMIT :limit
                SQL,
            ['category' => StationImporter::CATEGORY_CODE, 'lon' => $lon, 'lat' => $lat, 'limit' => self::CANDIDATES],
        );

        $routes = [];
        foreach ($candidates as $candidate) {
            usleep(self::THROTTLE_MICROSECONDS);
            $route = $this->routeClient->route($lon, $lat, (float) $candidate['lon'], (float) $candidate['lat']);
            if (null !== $route) {
                $routes[] = ['station' => $candidate['external_id'], ...$route];
            }
        }

        usort($routes, static fn (array $a, array $b): int => $a['duration'] <=> $b['duration']);

        return $routes;
    }
}
