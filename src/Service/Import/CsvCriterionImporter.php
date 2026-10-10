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
 * Covers any additive criterion shaped as one CSV row = one (commune code,
 * period, value) tuple: values are summed per unité urbaine, the only level
 * stored. Communes outside every unité urbaine are skipped. Delegates the
 * actual upsert to {@see CriterionValueWriter}.
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
        // A non-additive criterion (an average, a label...) needs its own
        // aggregation rule — decide it when the first one shows up.
        if (!$spec->valueType->isSummable()) {
            throw new \LogicException(\sprintf('Cannot import criterion "%s": values are summed per unité urbaine, which does not apply to %s.', $spec->code, $spec->valueType->value));
        }

        $criterionId = $this->findOrCreateCriterion($spec)->getId();
        $uniteUrbaineIdsByCommuneCode = $this->loadUniteUrbaineIdsByCommuneCode();

        /** @var array<string, array<int, float>> $sums indexed by unité urbaine id, then period */
        $sums = [];
        foreach ($this->csvReader->read($csvPath) as $row) {
            $uniteUrbaineId = $uniteUrbaineIdsByCommuneCode[$row[$spec->communeCodeColumn]] ?? null;
            if (null === $uniteUrbaineId) {
                continue; // Commune outside every unité urbaine — out of scope.
            }

            $period = (int) $row[$spec->periodColumn];
            $sums[$uniteUrbaineId][$period] = ($sums[$uniteUrbaineId][$period] ?? 0.0) + (float) $row[$spec->valueColumn];
        }

        $valueColumn = $spec->valueType->valueColumn();
        $count = 0;
        $batch = [];

        foreach ($sums as $uniteUrbaineId => $sumsByPeriod) {
            foreach ($sumsByPeriod as $period => $sum) {
                $batch[] = [
                    'id' => Uuid::v7(),
                    'uniteUrbaineId' => Uuid::fromString($uniteUrbaineId),
                    'period' => $period,
                    'value' => $this->castSum($spec->valueType, $sum),
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
        $criterion = $this->criterionRepository->findOneBy(['code' => $spec->code]);
        if (null !== $criterion) {
            // Keeps a criterion created before groups existed in step with its spec.
            if ($criterion->getGroupLabel() !== $spec->groupLabel) {
                $criterion->setGroupLabel($spec->groupLabel);
                $this->entityManager->flush();
            }

            return $criterion;
        }

        $criterion = new Criterion($spec->code, $spec->label, $spec->valueType);
        $criterion->setUnit($spec->unit);
        $criterion->setGroupLabel($spec->groupLabel);

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

    private function castSum(CriterionValueType $valueType, float $sum): int|string
    {
        return match ($valueType) {
            CriterionValueType::Integer => (int) round($sum),
            CriterionValueType::Decimal => (string) $sum,
            default => throw new \LogicException('Unreachable — guarded in import().'),
        };
    }
}
