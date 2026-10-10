<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Enum\JourneyDestination;
use App\Service\Sncf\DayJourneys;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Reads the vendored `resources/geo/trajets-gares.csv` (see
 * StationJourneySourceDownloader) and upserts one `station_journey` row per
 * station and destination, matching stations on their source id. Works
 * offline: the API is only called to refresh the file. Idempotent; drops the
 * rows the file no longer lists. Needs the stations imported first.
 */
final readonly class StationJourneyImporter
{
    public function __construct(
        private Connection $connection,
        private CsvReader $csvReader,
    ) {
    }

    /**
     * @return array{imported: int, skipped: int} skipped = lines for a station or destination not known here
     */
    public function import(string $csvPath): array
    {
        $startedAt = new \DateTimeImmutable();

        /** @var array<string, string> $stationIds */
        $stationIds = $this->connection->fetchAllKeyValue(
            <<<'SQL'
                SELECT poi.external_id, poi.id
                FROM poi
                INNER JOIN poi_category ON poi_category.id = poi.category_id
                WHERE poi_category.code = :category AND poi.external_id IS NOT NULL
                SQL,
            ['category' => StationImporter::CATEGORY_CODE],
        );

        /** @var list<string> $touchedIds */
        $touchedIds = [];
        $skipped = 0;
        foreach ($this->csvReader->read($csvPath) as $row) {
            $stationId = $stationIds[$row['id_gare']] ?? null;
            $destination = JourneyDestination::tryFrom($row['destination']);
            if (null === $stationId || null === $destination) {
                ++$skipped;

                continue;
            }

            $journeys = DayJourneys::fromCsv($row['transfers'], $row['departures']);

            $id = $this->connection->fetchOne(
                <<<'SQL'
                    INSERT INTO station_journey (id, station_id, destination, reference_date, transfers, trains_per_day, median_duration_minutes, created_at, updated_at)
                    VALUES (:id, :station, :destination, :referenceDate, :transfers, :trainsPerDay, :medianDuration, :now, :now)
                    ON CONFLICT (station_id, destination)
                    DO UPDATE SET reference_date = EXCLUDED.reference_date,
                                  transfers = EXCLUDED.transfers,
                                  trains_per_day = EXCLUDED.trains_per_day,
                                  median_duration_minutes = EXCLUDED.median_duration_minutes,
                                  updated_at = EXCLUDED.updated_at
                    RETURNING id
                    SQL,
                [
                    'id' => Uuid::v7()->toRfc4122(),
                    'station' => $stationId,
                    'destination' => $destination->value,
                    'referenceDate' => $row['reference_date'],
                    'transfers' => $journeys?->transfers,
                    'trainsPerDay' => $journeys?->trainsPerDay(),
                    'medianDuration' => $journeys?->medianDurationMinutes(),
                    'now' => $startedAt->format('Y-m-d H:i:s'),
                ],
            );
            \assert(\is_string($id));
            $touchedIds[] = $id;
        }

        // What was not touched this run is no longer in the file.
        if ([] === $touchedIds) {
            $this->connection->executeStatement('DELETE FROM station_journey');
        } else {
            $this->connection->executeStatement(
                'DELETE FROM station_journey WHERE id::text NOT IN (:touchedIds)',
                ['touchedIds' => $touchedIds],
                ['touchedIds' => ArrayParameterType::STRING],
            );
        }

        return ['imported' => \count($touchedIds), 'skipped' => $skipped];
    }
}
