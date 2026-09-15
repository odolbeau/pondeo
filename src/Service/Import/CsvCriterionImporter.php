<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Entity\Criterion;
use App\Enum\CriterionValueType;
use App\Enum\ZoneType;
use App\Repository\CriterionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Generic CSV → ZoneCriterionValue importer, driven by a {@see CriterionCsvSpec}.
 * Covers any criterion shaped as one CSV row = one (zone code, period,
 * value) tuple — which is every criterion so far. Delegates the actual
 * upsert to {@see ZoneCriterionValueWriter}.
 */
final readonly class CsvCriterionImporter
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CriterionRepository $criterionRepository,
        private CsvReader $csvReader,
        private ZoneCriterionValueWriter $zoneCriterionValueWriter,
    ) {
    }

    public function import(CriterionCsvSpec $spec, string $csvPath): int
    {
        $criterionId = $this->findOrCreateCriterion($spec)->getId();
        $zoneIdsByCode = $this->loadZoneIdsByCode($spec->zoneType);

        $zoneType = $spec->zoneType;
        $fkColumn = $zoneType->foreignKeyColumn();
        $valueColumn = $spec->valueType->valueColumn();

        $count = 0;
        $batch = [];

        foreach ($this->csvReader->read($csvPath) as $row) {
            $zoneId = $zoneIdsByCode[$row[$spec->zoneCodeColumn]] ?? null;
            if (null === $zoneId) {
                continue; // Zone not in scope (or genuinely unmapped) — skip.
            }

            $batch[] = [
                'id' => Uuid::v7(),
                'zoneId' => $zoneId,
                'period' => (int) $row[$spec->periodColumn],
                'value' => $this->castValue($spec->valueType, $row[$spec->valueColumn]),
            ];
            ++$count;

            if (\count($batch) >= ZoneCriterionValueWriter::BATCH_SIZE) {
                $this->zoneCriterionValueWriter->upsertBatch($criterionId, $zoneType, $fkColumn, $valueColumn, $batch);
                $batch = [];
            }
        }

        $this->zoneCriterionValueWriter->upsertBatch($criterionId, $zoneType, $fkColumn, $valueColumn, $batch);

        return $count;
    }

    private function findOrCreateCriterion(CriterionCsvSpec $spec): Criterion
    {
        $criterion = $this->criterionRepository->findOneBy(['code' => $spec->code]);
        if (null !== $criterion) {
            return $criterion;
        }

        $criterion = new Criterion($spec->code, $spec->label, $spec->valueType);
        $criterion->setUnit($spec->unit);
        $criterion->setZoneType($spec->zoneType);

        $this->entityManager->persist($criterion);
        $this->entityManager->flush();

        return $criterion;
    }

    /**
     * @return array<string, Uuid> zone id indexed by natural code
     */
    private function loadZoneIdsByCode(ZoneType $zoneType): array
    {
        $zoneClass = $zoneType->entityClass();

        /** @var array<string, Uuid> $idsByCode */
        $idsByCode = [];
        foreach ($this->entityManager->getRepository($zoneClass)->findAll() as $zone) {
            $idsByCode[$zone->getCode()] = $zone->getId();
        }

        $this->entityManager->clear();

        return $idsByCode;
    }

    private function castValue(CriterionValueType $valueType, string $raw): int|string|bool
    {
        return match ($valueType) {
            CriterionValueType::Integer => (int) $raw,
            CriterionValueType::Decimal => (string) (float) $raw,
            CriterionValueType::Text => $raw,
            CriterionValueType::Boolean => \in_array(strtolower($raw), ['1', 'true', 'yes'], true),
        };
    }
}
