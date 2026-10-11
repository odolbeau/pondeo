<?php

declare(strict_types=1);

namespace App\Service\Import;

use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Loads the vendored `resources/geo/gares-acces-voiture.csv` (see
 * StationAccessSourceDownloader) into `station_access`, replacing what was
 * there. Works offline; needs the zones and the stations imported first.
 */
final readonly class StationAccessImporter
{
    public function __construct(
        private CsvReader $csvReader,
        private Connection $connection,
    ) {
    }

    /**
     * @return int number of unité urbaine / station links imported
     */
    public function import(string $csvPath): int
    {
        /** @var array<string, string> $unitIds */
        $unitIds = $this->connection->fetchAllKeyValue('SELECT insee_code, id FROM unite_urbaine');
        /** @var array<string, string> $stationIds */
        $stationIds = $this->connection->fetchAllKeyValue(
            'SELECT p.external_id, p.id FROM poi p INNER JOIN poi_category pc ON pc.id = p.category_id AND pc.code = :category',
            ['category' => StationImporter::CATEGORY_CODE],
        );

        $now = new \DateTimeImmutable()->format('Y-m-d H:i:s');

        return (int) $this->connection->transactional(function () use ($csvPath, $unitIds, $stationIds, $now): int {
            $this->connection->executeStatement('DELETE FROM station_access');

            $count = 0;
            foreach ($this->csvReader->read($csvPath) as $row) {
                $unitId = $unitIds[$row['unite_urbaine_code']] ?? null;
                $stationId = $stationIds[$row['station_id']] ?? null;
                if (null === $unitId || null === $stationId) {
                    continue;
                }

                $this->connection->insert('station_access', [
                    'id' => Uuid::v7()->toRfc4122(),
                    'unite_urbaine_id' => $unitId,
                    'station_id' => $stationId,
                    'position' => (int) $row['position'],
                    'distance_meters' => (int) $row['distance_m'],
                    'duration_seconds' => (int) $row['duration_s'],
                    'period' => (int) $row['period'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                ++$count;
            }

            return $count;
        });
    }
}
