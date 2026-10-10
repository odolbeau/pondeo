<?php

declare(strict_types=1);

namespace App\Service\Import;

use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Shared batch upsert for `criterion_value` rows, used by anything that
 * writes values for a criterion. Upserts via a single `INSERT ... ON
 * CONFLICT ... DO UPDATE` per batch, targeting the (criterion, unité
 * urbaine, period) unique constraint — handles create and update uniformly,
 * without preloading existing rows or going through the ORM per row.
 */
final readonly class CriterionValueWriter
{
    public const int BATCH_SIZE = 500;

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @param list<array{id: Uuid, uniteUrbaineId: Uuid, period: int, value: int|string|bool}> $rows
     */
    public function upsertBatch(Uuid $criterionId, string $valueColumn, array $rows): void
    {
        if ([] === $rows) {
            return;
        }

        $now = new \DateTimeImmutable()->format('Y-m-d H:i:s');

        $placeholders = [];
        $params = [
            'criterionId' => $criterionId->toRfc4122(),
            'now' => $now,
        ];

        foreach ($rows as $i => $row) {
            $placeholders[] = "(:id{$i}, :criterionId, :uniteUrbaineId{$i}, :period{$i}, :value{$i}, :now, :now)";
            $params["id{$i}"] = $row['id']->toRfc4122();
            $params["uniteUrbaineId{$i}"] = $row['uniteUrbaineId']->toRfc4122();
            $params["period{$i}"] = $row['period'];
            $params["value{$i}"] = $row['value'];
        }

        $this->connection->executeStatement(
            \sprintf(
                <<<'SQL'
                    INSERT INTO criterion_value (id, criterion_id, unite_urbaine_id, period, %1$s, created_at, updated_at)
                    VALUES %2$s
                    ON CONFLICT (criterion_id, unite_urbaine_id, period)
                    DO UPDATE SET %1$s = EXCLUDED.%1$s, updated_at = EXCLUDED.updated_at
                    SQL,
                $valueColumn,
                implode(', ', $placeholders),
            ),
            $params,
        );
    }
}
