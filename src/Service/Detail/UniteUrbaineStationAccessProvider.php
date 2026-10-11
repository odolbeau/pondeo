<?php

declare(strict_types=1);

namespace App\Service\Detail;

use App\Entity\UniteUrbaine;
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
        /** @var list<array{name: string, distance_meters: int, duration_seconds: int}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT p.name, sa.distance_meters, sa.duration_seconds
                FROM station_access sa
                INNER JOIN poi p ON p.id = sa.station_id
                WHERE sa.unite_urbaine_id = :id
                ORDER BY sa.position
                SQL,
            ['id' => $uniteUrbaine->getId()->toRfc4122()],
        );

        return array_map(static fn (array $row): StationAccessDetail => new StationAccessDetail($row['name'], $row['distance_meters'], $row['duration_seconds']), $rows);
    }
}
