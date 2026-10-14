<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Enum\CriterionValueType;
use App\Enum\ShopType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Imports the shop counts of the BPE (see `docs/criteria-commerces.md`): one
 * count criterion for the total and one per sub-domain, each paired with a
 * density per 1000 inhabitants. The file holds the counts per unité urbaine
 * and shop type: the criteria sum them by sub-domain, and the detail by type
 * is kept on the unité urbaine as is. Densities are derived from the stored
 * counts and population, never summed.
 */
final readonly class CommercesImporter
{
    public const string GROUP = 'Commerces';

    /**
     * Indexed by count criterion code; a null sub-domain means all of them.
     * The detail page shows the density under its own label, with the count
     * in the title; the count criterion itself has no row (see
     * UniteUrbaineCriteriaProvider::DENSITY_SUFFIX). The position puts the total first; the group
     * title already says "Commerces", so the labels do not repeat it.
     *
     * @var array<string, array{label: string, densityLabel: string, subDomain: ?string, position: int}>
     */
    private const array COUNTS = [
        'commerces_total' => ['label' => 'Nombre de commerces', 'densityLabel' => 'Total', 'subDomain' => null, 'position' => 0],
        'commerces_grandes_surfaces' => ['label' => 'Nombre de grandes surfaces', 'densityLabel' => 'Grandes surfaces', 'subDomain' => 'B1', 'position' => 3],
        'commerces_alimentaires' => ['label' => 'Nombre de commerces alimentaires', 'densityLabel' => 'Alimentaires', 'subDomain' => 'B2', 'position' => 1],
        'commerces_non_alimentaires' => ['label' => 'Nombre de commerces non alimentaires', 'densityLabel' => 'Non alimentaires', 'subDomain' => 'B3', 'position' => 2],
    ];

    public function __construct(
        private CsvCriterionImporter $csvCriterionImporter,
        private DensityPerThousandDeriver $densityPerThousandDeriver,
        private CsvReader $csvReader,
        private CriterionValueWriter $criterionValueWriter,
        private Connection $connection,
    ) {
    }

    /**
     * @return int number of (unité urbaine, period) values upserted, counts and densities together
     */
    public function import(string $csvPath): int
    {
        $totals = $this->sumPerUniteUrbaine($csvPath);
        $this->storeTypes($csvPath);
        $count = 0;

        foreach (self::COUNTS as $code => ['label' => $label, 'densityLabel' => $densityLabel, 'subDomain' => $subDomain, 'position' => $position]) {
            $count += $this->importCount($code, $label, $subDomain, $totals);
            $count += $this->densityPerThousandDeriver->derive($code, $densityLabel, self::GROUP, $position);
        }

        return $count;
    }

    /**
     * The sub-domain totals are derived from the shop types of the file; a
     * null sub-domain stands for the total over all of them.
     *
     * @param array<string, array<int, array<string, int>>> $totals shops by unité urbaine code, year, then sub-domain
     */
    private function importCount(string $code, string $label, ?string $subDomain, array $totals): int
    {
        $criterion = $this->csvCriterionImporter->findOrCreate($code, $label, CriterionValueType::Integer, 'commerces', self::GROUP, comparable: false);
        $uniteUrbaineIds = $this->connection->fetchAllKeyValue('SELECT insee_code, id FROM unite_urbaine');

        $count = 0;
        $batch = [];
        foreach ($totals as $uniteUrbaineCode => $totalsByYear) {
            $uniteUrbaineId = $uniteUrbaineIds[$uniteUrbaineCode] ?? null;
            if (!\is_string($uniteUrbaineId)) {
                continue; // Unité urbaine out of scope.
            }

            foreach ($totalsByYear as $year => $totalsBySubDomain) {
                $batch[] = [
                    'id' => Uuid::v7(),
                    'uniteUrbaineId' => Uuid::fromString($uniteUrbaineId),
                    'period' => $year,
                    'value' => null === $subDomain ? array_sum($totalsBySubDomain) : ($totalsBySubDomain[$subDomain] ?? 0),
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
     * Keeps the detail by type on each unité urbaine, for the detail page.
     * The years of the file are shared by all units; a unit with no row for
     * a type and year has none.
     */
    private function storeTypes(string $csvPath): void
    {
        /** @var array<string, array<string, array<int, int>>> $byUniteUrbaine shops by unité urbaine code, type, then year */
        $byUniteUrbaine = [];
        $years = [];
        foreach ($this->csvReader->read($csvPath) as $row) {
            $type = ShopType::tryFrom($row['type']);
            if (null === $type) {
                continue;
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

    /**
     * @return array<string, array<int, array<string, int>>> shops by unité urbaine code, year, then sub-domain
     */
    private function sumPerUniteUrbaine(string $csvPath): array
    {
        $totals = [];
        foreach ($this->csvReader->read($csvPath) as $row) {
            $type = ShopType::tryFrom($row['type']);
            if (null === $type) {
                continue; // A type this version does not know: ignored rather than counted in the wrong family.
            }

            $year = (int) $row['year'];
            $totals[$row['code_unite_urbaine']][$year][$type->subDomain()] = ($totals[$row['code_unite_urbaine']][$year][$type->subDomain()] ?? 0) + (int) $row['count'];
        }

        return $totals;
    }
}
