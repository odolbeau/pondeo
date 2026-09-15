<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Enum\CriterionValueType;
use App\Enum\ZoneType;
use App\Repository\CriterionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Sums a criterion's commune-level values up to a parent zone type (EPCI,
 * département or région), one row per (parent zone, period). Only correct
 * for criteria whose values are meaningfully additive (e.g. population) —
 * summing wouldn't make sense for, say, an average temperature, so callers
 * opt in per criterion rather than this running automatically for every one.
 */
final readonly class CriterionAggregator
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CriterionRepository $criterionRepository,
        private ZoneCriterionValueWriter $zoneCriterionValueWriter,
    ) {
    }

    public function aggregate(string $criterionCode, ZoneType $targetZoneType): int
    {
        if (ZoneType::Commune === $targetZoneType) {
            throw new \InvalidArgumentException('Cannot aggregate to zone type "commune" — a commune is always the aggregation source, never a target.');
        }

        $criterion = $this->criterionRepository->findOneBy(['code' => $criterionCode]);
        if (null === $criterion) {
            throw new \InvalidArgumentException(\sprintf('Unknown criterion "%s".', $criterionCode));
        }

        $valueType = $criterion->getValueType();
        if (!$valueType->isSummable()) {
            throw new \LogicException(\sprintf('Cannot sum criterion "%s" — SUM aggregation only applies to Integer/Decimal criteria, got %s.', $criterionCode, $valueType->value));
        }

        $criterionId = $criterion->getId();
        $fkColumn = $targetZoneType->foreignKeyColumn();
        $valueColumn = $valueType->valueColumn();
        $connection = $this->entityManager->getConnection();

        /** @var list<array{zone_id: string, period: int, value: int|float|string}> $rows */
        $rows = $connection->fetchAllAssociative(
            \sprintf(
                <<<'SQL'
                    SELECT commune.%1$s AS zone_id, zcv.period AS period, SUM(zcv.%2$s) AS value
                    FROM zone_criterion_value zcv
                    INNER JOIN commune ON commune.id = zcv.commune_id
                    WHERE zcv.criterion_id = :criterionId AND commune.%1$s IS NOT NULL
                    GROUP BY commune.%1$s, zcv.period
                    SQL,
                $fkColumn,
                $valueColumn,
            ),
            ['criterionId' => $criterionId->toRfc4122()],
        );

        $count = 0;
        $batch = [];

        foreach ($rows as $row) {
            $batch[] = [
                'id' => Uuid::v7(),
                'zoneId' => Uuid::fromString($row['zone_id']),
                'period' => (int) $row['period'],
                'value' => $this->castSum($valueType, $row['value']),
            ];
            ++$count;

            if (\count($batch) >= ZoneCriterionValueWriter::BATCH_SIZE) {
                $this->zoneCriterionValueWriter->upsertBatch($criterionId, $targetZoneType, $fkColumn, $valueColumn, $batch);
                $batch = [];
            }
        }

        $this->zoneCriterionValueWriter->upsertBatch($criterionId, $targetZoneType, $fkColumn, $valueColumn, $batch);

        // Criterion::zoneType is documented as "null = applies to every zone
        // type" — clear it once the criterion has values beyond the single
        // zone type it was first imported for, so it stops being misleading.
        if (null !== $criterion->getZoneType()) {
            $criterion->setZoneType(null);
            $this->entityManager->flush();
        }

        return $count;
    }

    private function castSum(CriterionValueType $valueType, int|float|string $sum): int|string
    {
        return match ($valueType) {
            CriterionValueType::Integer => (int) $sum,
            CriterionValueType::Decimal => (string) (float) $sum,
            default => throw new \LogicException('Unreachable — guarded in aggregate().'),
        };
    }
}
