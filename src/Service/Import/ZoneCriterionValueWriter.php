<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Enum\ZoneType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Shared batch upsert for `zone_criterion_value` rows, used by anything that
 * writes values for a criterion (CSV import, aggregation, ...). Upserts via a
 * single `INSERT ... ON CONFLICT ... DO UPDATE` per batch, targeting the
 * partial unique index for the given zone type (see ZoneCriterionValue's
 * docblock) — handles create and update uniformly, without preloading
 * existing rows or going through the ORM per row.
 */
final readonly class ZoneCriterionValueWriter
{
    public const int BATCH_SIZE = 500;

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @param list<array{id: Uuid, zoneId: Uuid, period: int, value: int|string|bool}> $rows
     */
    public function upsertBatch(Uuid $criterionId, ZoneType $zoneType, string $fkColumn, string $valueColumn, array $rows): void
    {
        if ([] === $rows) {
            return;
        }

        $now = new \DateTimeImmutable()->format('Y-m-d H:i:s');

        $placeholders = [];
        $params = [
            'criterionId' => $criterionId->toRfc4122(),
            'zoneType' => $zoneType->value,
            'now' => $now,
        ];

        foreach ($rows as $i => $row) {
            $placeholders[] = "(:id{$i}, :criterionId, :zoneType, :zoneId{$i}, :period{$i}, :value{$i}, :now, :now)";
            $params["id{$i}"] = $row['id']->toRfc4122();
            $params["zoneId{$i}"] = $row['zoneId']->toRfc4122();
            $params["period{$i}"] = $row['period'];
            $params["value{$i}"] = $row['value'];
        }

        $this->connection->executeStatement(
            \sprintf(
                <<<'SQL'
                    INSERT INTO zone_criterion_value (id, criterion_id, zone_type, %1$s, period, %2$s, created_at, updated_at)
                    VALUES %3$s
                    ON CONFLICT (criterion_id, %1$s, period) WHERE %1$s IS NOT NULL
                    DO UPDATE SET %2$s = EXCLUDED.%2$s, updated_at = EXCLUDED.updated_at
                    SQL,
                $fkColumn,
                $valueColumn,
                implode(', ', $placeholders),
            ),
            $params,
        );
    }
}
