<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Enum\CriterionValueType;
use App\Repository\CriterionRepository;
use App\Service\Detail\UniteUrbaineCriteriaProvider;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Imports the shop counts of the BPE (see `docs/criteria-commerces.md`): one
 * count criterion for the total and one per sub-domain, each paired with a
 * density per 1000 inhabitants. Counts are additive and summed per unité
 * urbaine like any criterion; densities are derived from the stored counts
 * and population, never summed.
 */
final readonly class CommercesImporter
{
    public const string GROUP = 'Commerces';

    /** BPE sub-domains of the commerce domain. */
    public const array SUB_DOMAINS = ['B1', 'B2', 'B3'];

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
        private CriterionValueWriter $criterionValueWriter,
        private CriterionRepository $criterionRepository,
        private Connection $connection,
    ) {
    }

    /**
     * @return int number of (unité urbaine, period) values upserted, counts and densities together
     */
    public function import(string $csvPath): int
    {
        $count = 0;

        foreach (self::COUNTS as $code => ['label' => $label, 'densityLabel' => $densityLabel, 'subDomain' => $subDomain, 'position' => $position]) {
            $count += $this->csvCriterionImporter->import(new CriterionCsvSpec(
                code: $code,
                label: $label,
                valueType: CriterionValueType::Integer,
                unit: 'commerces',
                communeCodeColumn: 'code_insee',
                periodColumn: 'year',
                valueColumn: 'count',
                groupLabel: self::GROUP,
                filterColumn: null === $subDomain ? null : 'sub_domain',
                filterValue: $subDomain,
                comparable: false,
            ), $csvPath);

            $count += $this->importDensity($code, $densityLabel, $position);
        }

        return $count;
    }

    private function importDensity(string $countCode, string $label, int $position): int
    {
        $populationId = $this->criterionRepository->findOneBy(['code' => 'population'])?->getId();
        if (null === $populationId) {
            throw new \LogicException('Criterion "population" not found: import it first.');
        }

        $countId = $this->criterionRepository->findOneBy(['code' => $countCode])?->getId();
        if (null === $countId) {
            throw new \LogicException("Criterion \"{$countCode}\" not found.");
        }

        $density = $this->csvCriterionImporter->findOrCreate(
            $countCode.UniteUrbaineCriteriaProvider::DENSITY_SUFFIX,
            $label,
            CriterionValueType::Decimal,
            'pour 1000 hab.',
            self::GROUP,
            position: $position,
        );

        // The population of the latest year not after the count's: the census
        // series ends before the BPE does.
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT n.unite_urbaine_id, n.period, n.value_integer AS shops, p.value_integer AS population
                FROM criterion_value n
                CROSS JOIN LATERAL (
                    SELECT value_integer
                    FROM criterion_value
                    WHERE criterion_id = :populationId
                      AND unite_urbaine_id = n.unite_urbaine_id
                      AND period <= n.period
                    ORDER BY period DESC
                    LIMIT 1
                ) p
                WHERE n.criterion_id = :countId AND p.value_integer > 0
                SQL,
            ['populationId' => $populationId->toRfc4122(), 'countId' => $countId->toRfc4122()],
        );

        $batch = [];
        foreach ($rows as $row) {
            $batch[] = [
                'id' => Uuid::v7(),
                'uniteUrbaineId' => Uuid::fromString(\is_string($row['unite_urbaine_id']) ? $row['unite_urbaine_id'] : throw new \UnexpectedValueException('Unexpected unité urbaine id.')),
                'period' => (int) (is_numeric($row['period']) ? $row['period'] : 0),
                'value' => number_format((int) (is_numeric($row['shops']) ? $row['shops'] : 0) / (int) (is_numeric($row['population']) ? $row['population'] : 1) * 1000, 2, '.', ''),
            ];

            if (\count($batch) >= CriterionValueWriter::BATCH_SIZE) {
                $this->criterionValueWriter->upsertBatch($density->getId(), CriterionValueType::Decimal->valueColumn(), $batch);
                $batch = [];
            }
        }

        $this->criterionValueWriter->upsertBatch($density->getId(), CriterionValueType::Decimal->valueColumn(), $batch);

        return \count($rows);
    }
}
