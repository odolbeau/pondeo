<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Entity\Commune;
use App\Entity\Criterion;
use App\Entity\Departement;
use App\Entity\Epci;
use App\Entity\Region;
use App\Entity\ZoneInterface;
use App\Enum\CriterionValueType;
use App\Enum\ZoneType;
use App\Repository\CriterionRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Generic CSV → ZoneCriterionValue importer, driven by a {@see CriterionCsvSpec}.
 * Covers any criterion shaped as one CSV row = one (zone code, period,
 * value) tuple — which is every criterion so far. Upserts in batches via a
 * single `INSERT ... ON CONFLICT ... DO UPDATE` per batch, targeting the
 * partial unique index for the criterion's zone type (see
 * ZoneCriterionValue's docblock) — handles create and update uniformly,
 * without preloading existing rows or going through the ORM per row.
 */
final readonly class CsvCriterionImporter
{
    private const int BATCH_SIZE = 500;

    /** @var array<string, class-string<ZoneInterface>> */
    private const array ZONE_ENTITY_CLASS_BY_TYPE = [
        ZoneType::Commune->value => Commune::class,
        ZoneType::Epci->value => Epci::class,
        ZoneType::Departement->value => Departement::class,
        ZoneType::Region->value => Region::class,
    ];

    // Never interpolated from external input — same "fixed allowlist"
    // principle as NearestPoiDistanceFinder::TABLE_BY_ZONE_TYPE.
    private const array FK_COLUMN_BY_ZONE_TYPE = [
        ZoneType::Commune->value => 'commune_id',
        ZoneType::Epci->value => 'epci_id',
        ZoneType::Departement->value => 'departement_id',
        ZoneType::Region->value => 'region_id',
    ];

    private const array VALUE_COLUMN_BY_VALUE_TYPE = [
        CriterionValueType::Integer->value => 'value_integer',
        CriterionValueType::Decimal->value => 'value_decimal',
        CriterionValueType::Text->value => 'value_text',
        CriterionValueType::Boolean->value => 'value_boolean',
    ];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private CriterionRepository $criterionRepository,
        private CsvReader $csvReader,
    ) {
    }

    public function import(CriterionCsvSpec $spec, string $csvPath): int
    {
        $criterionId = $this->findOrCreateCriterion($spec)->getId();
        $zoneIdsByCode = $this->loadZoneIdsByCode($spec->zoneType);

        $connection = $this->entityManager->getConnection();
        $zoneType = $spec->zoneType;
        $fkColumn = self::FK_COLUMN_BY_ZONE_TYPE[$zoneType->value];
        $valueColumn = self::VALUE_COLUMN_BY_VALUE_TYPE[$spec->valueType->value];

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

            if (\count($batch) >= self::BATCH_SIZE) {
                $this->upsertBatch($connection, $criterionId, $zoneType, $fkColumn, $valueColumn, $batch);
                $batch = [];
            }
        }

        $this->upsertBatch($connection, $criterionId, $zoneType, $fkColumn, $valueColumn, $batch);

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
        $zoneClass = self::ZONE_ENTITY_CLASS_BY_TYPE[$zoneType->value];

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

    /**
     * @param list<array{id: Uuid, zoneId: Uuid, period: int, value: int|string|bool}> $rows
     */
    private function upsertBatch(Connection $connection, Uuid $criterionId, ZoneType $zoneType, string $fkColumn, string $valueColumn, array $rows): void
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

        $connection->executeStatement(
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
