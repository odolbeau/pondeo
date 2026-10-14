<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Enum\CriterionValueType;
use App\Enum\EquipmentFamily;
use App\Enum\ShopType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Imports the facility counts of the BPE (see `docs/equipements-bpe.md`): for
 * each {@see EquipmentFamily}, one count criterion paired with a density per
 * 1000 inhabitants. The file holds the counts per unité urbaine and facility
 * type; the criteria sum the types of their family. The detail by shop type
 * is kept on the unité urbaine as is.
 */
final readonly class EquipmentImporter
{
    public function __construct(
        private CsvCriterionImporter $csvCriterionImporter,
        private CsvReader $csvReader,
        private CriterionValueWriter $criterionValueWriter,
        private DensityPerThousandDeriver $densityPerThousandDeriver,
        private Connection $connection,
    ) {
    }

    /**
     * @return int number of (unité urbaine, period) values upserted, counts and densities together
     */
    public function import(string $csvPath): int
    {
        $totals = $this->sumPerType($csvPath);
        $this->storeShopTypes($csvPath);
        $uniteUrbaineIds = $this->connection->fetchAllKeyValue('SELECT insee_code, id FROM unite_urbaine');
        $count = 0;

        foreach (EquipmentFamily::cases() as $family) {
            $count += $this->importCount($family, $totals, $uniteUrbaineIds);
            $count += $this->densityPerThousandDeriver->derive($family->value, $family->densityLabel(), $family->group(), $family->position());
        }

        return $count;
    }

    /**
     * @param array<string, array<int, array<string, int>>> $totals          facilities by unité urbaine code, year, then type
     * @param array<array-key, mixed>                       $uniteUrbaineIds unité urbaine id by INSEE code
     */
    private function importCount(EquipmentFamily $family, array $totals, array $uniteUrbaineIds): int
    {
        $criterion = $this->csvCriterionImporter->findOrCreate($family->value, $family->label(), CriterionValueType::Integer, $family->unit(), $family->group(), comparable: false);
        $types = array_flip($family->types());

        $count = 0;
        $batch = [];
        foreach ($totals as $uniteUrbaineCode => $totalsByYear) {
            $uniteUrbaineId = $uniteUrbaineIds[$uniteUrbaineCode] ?? null;
            if (!\is_string($uniteUrbaineId)) {
                continue; // Unité urbaine out of scope.
            }

            foreach ($totalsByYear as $year => $totalsByType) {
                $batch[] = [
                    'id' => Uuid::v7(),
                    'uniteUrbaineId' => Uuid::fromString($uniteUrbaineId),
                    'period' => $year,
                    'value' => array_sum(array_intersect_key($totalsByType, $types)),
                ];
                ++$count;

                if (\count($batch) >= CriterionValueWriter::BATCH_SIZE) {
                    $this->criterionValueWriter->upsertBatch($criterion->getId(), CriterionValueType::Integer->valueColumn(), $batch);
                    $batch = [];
                }
            }
        }

        $this->criterionValueWriter->upsertBatch($criterion->getId(), CriterionValueType::Integer->valueColumn(), $batch);

        return $count;
    }

    /**
     * Only the types of a family are kept: the file holds many more. A unité
     * urbaine and year with no row for the types of a family get a zero, since
     * the file lists a unit only where it has facilities.
     *
     * @return array<string, array<int, array<string, int>>> facilities by unité urbaine code, year, then type
     */
    private function sumPerType(string $csvPath): array
    {
        $wanted = [];
        foreach (EquipmentFamily::cases() as $family) {
            $wanted += array_flip($family->types());
        }

        $totals = [];
        foreach ($this->csvReader->read($csvPath) as $row) {
            if (!isset($wanted[$row['type']])) {
                continue;
            }

            $totals[$row['code_unite_urbaine']][(int) $row['year']][$row['type']] = (int) $row['count'];
        }

        return $totals;
    }

    /**
     * Keeps the detail by shop type on each unité urbaine, for the detail
     * page. The years of the file are shared by all units; a unit with no row
     * for a type and year has none.
     */
    private function storeShopTypes(string $csvPath): void
    {
        /** @var array<string, array<string, array<int, int>>> $byUniteUrbaine shops by unité urbaine code, type, then year */
        $byUniteUrbaine = [];
        $years = [];
        foreach ($this->csvReader->read($csvPath) as $row) {
            $type = ShopType::tryFrom($row['type']);
            if (null === $type) {
                continue; // Another domain than the shops.
            }

            $year = (int) $row['year'];
            $years[$year] = $year;
            $byUniteUrbaine[$row['code_unite_urbaine']][$type->value][$year] = (int) $row['count'];
        }

        sort($years);

        foreach ($byUniteUrbaine as $code => $byType) {
            ksort($byType);
            $counts = array_map(static fn (array $byYear): array => array_map(static fn (int $year): int => $byYear[$year] ?? 0, $years), $byType);

            $this->connection->executeStatement(
                'UPDATE unite_urbaine SET shop_types = :shopTypes WHERE insee_code = :code',
                ['shopTypes' => json_encode(['years' => $years, 'counts' => $counts], \JSON_THROW_ON_ERROR), 'code' => $code],
            );
        }
    }
}
