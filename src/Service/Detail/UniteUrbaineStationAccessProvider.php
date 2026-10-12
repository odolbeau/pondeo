<?php

declare(strict_types=1);

namespace App\Service\Detail;

use App\Entity\UniteUrbaine;
use App\Enum\JourneyDestination;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/** The closest railway stations of an unité urbaine, fastest first (see docs/criteria-station-access.md). */
final readonly class UniteUrbaineStationAccessProvider
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return list<StationAccessDetail>
     */
    public function find(UniteUrbaine $uniteUrbaine): array
    {
        /** @var list<array{station_id: string, name: string, distance_meters: int, duration_seconds: int}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT p.id AS station_id, p.name, sa.distance_meters, sa.duration_seconds
                FROM station_access sa
                INNER JOIN poi p ON p.id = sa.station_id
                WHERE sa.unite_urbaine_id = :id
                ORDER BY sa.position
                SQL,
            ['id' => $uniteUrbaine->getId()->toRfc4122()],
        );

        $journeys = $this->findJourneys(array_column($rows, 'station_id'));

        return array_map(static fn (array $row): StationAccessDetail => new StationAccessDetail($row['name'], $row['distance_meters'], $row['duration_seconds'], $journeys[$row['station_id']] ?? []), $rows);
    }

    /**
     * @param list<string> $stationIds
     *
     * @return array<string, list<StationJourneyDetail>> by station id, fastest first
     */
    private function findJourneys(array $stationIds): array
    {
        if ([] === $stationIds) {
            return [];
        }

        /** @var list<array{station_id: string, destination: string, reference_date: string, transfers: ?int, trains_per_day: ?int, median_duration_minutes: ?int}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT station_id, destination, reference_date, transfers, trains_per_day, median_duration_minutes FROM station_journey WHERE station_id IN (:ids)',
            ['ids' => $stationIds],
            ['ids' => ArrayParameterType::STRING],
        );

        $journeys = [];
        foreach ($rows as $row) {
            $journeys[$row['station_id']][] = new StationJourneyDetail(JourneyDestination::from($row['destination']), $row['transfers'], $row['trains_per_day'], $row['median_duration_minutes'], new \DateTimeImmutable($row['reference_date']));
        }

        foreach ($journeys as &$stationJourneys) {
            // Unreachable destinations last.
            usort($stationJourneys, static fn (StationJourneyDetail $a, StationJourneyDetail $b): int => ($a->medianDurationMinutes ?? \PHP_INT_MAX) <=> ($b->medianDurationMinutes ?? \PHP_INT_MAX));
        }

        return $journeys;
    }
}
