<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Entity\Criterion;
use App\Enum\CriterionValueType;
use App\Repository\CriterionRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Generic CSV → CriterionValue importer, driven by a {@see CriterionCsvSpec}.
 * Covers any numeric criterion shaped as one CSV row = one (commune code,
 * period, value) tuple: values are aggregated per unité urbaine (see
 * {@see Aggregation}), the only level stored. A CSV already keyed by unité
 * urbaine is stored as is. Rows outside every unité urbaine are skipped.
 * Delegates the actual upsert to {@see CriterionValueWriter}.
 */
final readonly class CsvCriterionImporter
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private Connection $connection,
        private CriterionRepository $criterionRepository,
        private CsvReader $csvReader,
        private CriterionValueWriter $criterionValueWriter,
    ) {
    }

    /**
     * @return int number of (unité urbaine, period) values upserted
     */
    public function import(CriterionCsvSpec $spec, string $csvPath): int
    {
        if (!$spec->valueType->isNumeric()) {
            throw new \LogicException(\sprintf('Cannot import criterion "%s": values are aggregated per unité urbaine, which does not apply to %s.', $spec->code, $spec->valueType->value));
        }

        if (!$spec->perUniteUrbaine && Aggregation::WeightedMean === $spec->aggregation && null === $spec->weightColumn) {
            throw new \LogicException(\sprintf('Cannot import criterion "%s": a weighted mean needs a weight column.', $spec->code));
        }

        $criterionId = $this->findOrCreateCriterion($spec)->getId();
        $uniteUrbaineIds = $spec->perUniteUrbaine ? $this->loadUniteUrbaineIdsByCode() : $this->loadUniteUrbaineIdsByCommuneCode();

        /** @var array<string, array<int, array{value: float, weight: float}>> $accumulators indexed by unité urbaine id, then period */
        $accumulators = [];
        foreach ($this->csvReader->read($csvPath) as $row) {
            if (null !== $spec->filterColumn && $row[$spec->filterColumn] !== $spec->filterValue) {
                continue;
            }

            $uniteUrbaineId = $uniteUrbaineIds[$row[$spec->communeCodeColumn]] ?? null;
            if (null === $uniteUrbaineId) {
                continue; // Commune outside every unité urbaine — out of scope.
            }

            $period = (int) $row[$spec->periodColumn];
            $value = (float) $row[$spec->valueColumn];
            $previous = $accumulators[$uniteUrbaineId][$period] ?? null;

            if ($spec->perUniteUrbaine) {
                $accumulators[$uniteUrbaineId][$period] = ['value' => $value, 'weight' => 1.0];
            } elseif (Aggregation::Sum === $spec->aggregation) {
                $accumulators[$uniteUrbaineId][$period] = ['value' => ($previous['value'] ?? 0.0) + $value, 'weight' => 1.0];
            } elseif (Aggregation::Max === $spec->aggregation) {
                $accumulators[$uniteUrbaineId][$period] = ['value' => max($previous['value'] ?? $value, $value), 'weight' => 1.0];
            } else {
                \assert(null !== $spec->weightColumn); // Guarded above.
                $weight = (float) $row[$spec->weightColumn];
                $accumulators[$uniteUrbaineId][$period] = ['value' => ($previous['value'] ?? 0.0) + $weight * $value, 'weight' => ($previous['weight'] ?? 0.0) + $weight];
            }
        }

        $valueColumn = $spec->valueType->valueColumn();
        $count = 0;
        $batch = [];

        foreach ($accumulators as $uniteUrbaineId => $accumulatorsByPeriod) {
            foreach ($accumulatorsByPeriod as $period => $accumulator) {
                $value = $accumulator['value'];
                if (!$spec->perUniteUrbaine && Aggregation::WeightedMean === $spec->aggregation) {
                    if ($accumulator['weight'] <= 0) {
                        continue; // Nothing to weight the mean with.
                    }

                    $value /= $accumulator['weight'];
                }

                $batch[] = [
                    'id' => Uuid::v7(),
                    'uniteUrbaineId' => Uuid::fromString($uniteUrbaineId),
                    'period' => $period,
                    'value' => $this->cast($spec->valueType, $value, $spec->perUniteUrbaine),
                ];
                ++$count;

                if (\count($batch) >= CriterionValueWriter::BATCH_SIZE) {
                    $this->criterionValueWriter->upsertBatch($criterionId, $valueColumn, $batch);
                    $batch = [];
                }
            }
        }

        $this->criterionValueWriter->upsertBatch($criterionId, $valueColumn, $batch);

        return $count;
    }

    private function findOrCreateCriterion(CriterionCsvSpec $spec): Criterion
    {
        return $this->findOrCreate($spec->code, $spec->label, $spec->valueType, $spec->unit, $spec->groupLabel, $spec->comparable, $spec->position, $spec->rankFromLowest);
    }

    /**
     * Also used by importers that derive a criterion rather than read it from a CSV.
     */
    public function findOrCreate(string $code, string $label, CriterionValueType $valueType, ?string $unit, ?string $groupLabel, bool $comparable = true, ?int $position = null, bool $rankFromLowest = false): Criterion
    {
        $criterion = $this->criterionRepository->findOneBy(['code' => $code]);
        if (null !== $criterion) {
            // Keeps a criterion created before its label, group, comparability, position or rank direction changed in step with its spec.
            if ($criterion->getLabel() !== $label || $criterion->getGroupLabel() !== $groupLabel || $criterion->isComparable() !== $comparable || $criterion->getPosition() !== $position || $criterion->isRankFromLowest() !== $rankFromLowest) {
                $criterion->setLabel($label);
                $criterion->setGroupLabel($groupLabel);
                $criterion->setComparable($comparable);
                $criterion->setPosition($position);
                $criterion->setRankFromLowest($rankFromLowest);
                $this->entityManager->flush();
            }

            return $criterion;
        }

        $criterion = new Criterion($code, $label, $valueType);
        $criterion->setUnit($unit);
        $criterion->setGroupLabel($groupLabel);
        $criterion->setComparable($comparable);
        $criterion->setPosition($position);
        $criterion->setRankFromLowest($rankFromLowest);

        $this->entityManager->persist($criterion);
        $this->entityManager->flush();

        return $criterion;
    }

    /**
     * @return array<string, string> unité urbaine id indexed by commune INSEE code
     */
    private function loadUniteUrbaineIdsByCommuneCode(): array
    {
        /** @var array<string, string> $ids */
        $ids = $this->connection->fetchAllKeyValue('SELECT insee_code, unite_urbaine_id FROM commune');

        return $ids;
    }

    /**
     * @return array<string, string> unité urbaine id indexed by its INSEE code
     */
    private function loadUniteUrbaineIdsByCode(): array
    {
        /** @var array<string, string> $ids */
        $ids = $this->connection->fetchAllKeyValue('SELECT insee_code, id FROM unite_urbaine');

        return $ids;
    }

    private function cast(CriterionValueType $valueType, float $value, bool $asPublished): int|string
    {
        return match ($valueType) {
            CriterionValueType::Integer => (int) round($value),
            CriterionValueType::Decimal => $asPublished ? number_format($value, 2, '.', '') : (string) $value,
            default => throw new \LogicException('Unreachable — guarded in import().'),
        };
    }
}
