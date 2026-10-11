<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Enum\CriterionValueType;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Imports the census employment figures (see `docs/criteria-employment.md`):
 * the active and unemployed populations aged 15-64 are additive and summed
 * per unité urbaine like any criterion; the unemployment and activity rates
 * are derived from the stored sums, never summed. The counts are only a
 * means to the rates: they are dropped once the rates are derived.
 */
final readonly class EmploymentImporter
{
    public const string GROUP = 'Emploi';

    /**
     * Count criteria, indexed by code, read from the CSV rows of one status.
     *
     * @var array<string, array{label: string, status: string}>
     */
    private const array COUNTS = [
        'actifs_15_64' => ['label' => 'Actifs de 15 à 64 ans', 'status' => 'actifs'],
        'chomeurs_15_64' => ['label' => 'Chômeurs de 15 à 64 ans', 'status' => 'chomeurs'],
        'population_15_64' => ['label' => 'Population de 15 à 64 ans', 'status' => 'population'],
    ];

    public function __construct(
        private CsvCriterionImporter $csvCriterionImporter,
        private CriterionValueWriter $criterionValueWriter,
        private Connection $connection,
    ) {
    }

    /**
     * @return int number of (unité urbaine, period) rate values upserted
     */
    public function import(string $csvPath): int
    {
        foreach (self::COUNTS as $code => ['label' => $label, 'status' => $status]) {
            $this->csvCriterionImporter->import(new CriterionCsvSpec(
                code: $code,
                label: $label,
                valueType: CriterionValueType::Integer,
                unit: 'pers.',
                communeCodeColumn: 'code_insee',
                periodColumn: 'year',
                valueColumn: 'count',
                groupLabel: self::GROUP,
                filterColumn: 'status',
                filterValue: $status,
                comparable: false,
            ), $csvPath);
        }

        $count = $this->deriveRate('taux_chomage', 'Taux de chômage', 'chomeurs_15_64', 'actifs_15_64', 0);
        $count += $this->deriveRate('taux_activite', 'Taux d\'activité', 'actifs_15_64', 'population_15_64', 1);

        $this->dropCounts();

        return $count;
    }

    private function dropCounts(): void
    {
        $codes = array_keys(self::COUNTS);
        $this->connection->executeStatement('DELETE FROM criterion_value WHERE criterion_id IN (SELECT id FROM criterion WHERE code IN (:codes))', ['codes' => $codes], ['codes' => ArrayParameterType::STRING]);
        $this->connection->executeStatement('DELETE FROM criterion WHERE code IN (:codes)', ['codes' => $codes], ['codes' => ArrayParameterType::STRING]);
    }

    private function deriveRate(string $code, string $label, string $numeratorCode, string $denominatorCode, int $position): int
    {
        $rate = $this->csvCriterionImporter->findOrCreate($code, $label, CriterionValueType::Decimal, '%', self::GROUP, position: $position);

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT n.unite_urbaine_id, n.period, n.value_integer AS numerator, d.value_integer AS denominator
                FROM criterion_value n
                INNER JOIN criterion nc ON nc.id = n.criterion_id AND nc.code = :numeratorCode
                INNER JOIN criterion dc ON dc.code = :denominatorCode
                INNER JOIN criterion_value d ON d.criterion_id = dc.id
                    AND d.unite_urbaine_id = n.unite_urbaine_id AND d.period = n.period
                WHERE d.value_integer > 0
                SQL,
            ['numeratorCode' => $numeratorCode, 'denominatorCode' => $denominatorCode],
        );

        $batch = [];
        foreach ($rows as $row) {
            $batch[] = [
                'id' => Uuid::v7(),
                'uniteUrbaineId' => Uuid::fromString(\is_string($row['unite_urbaine_id']) ? $row['unite_urbaine_id'] : throw new \UnexpectedValueException('Unexpected unité urbaine id.')),
                'period' => (int) (is_numeric($row['period']) ? $row['period'] : 0),
                'value' => number_format((int) (is_numeric($row['numerator']) ? $row['numerator'] : 0) / (int) (is_numeric($row['denominator']) ? $row['denominator'] : 1) * 100, 2, '.', ''),
            ];

            if (\count($batch) >= CriterionValueWriter::BATCH_SIZE) {
                $this->criterionValueWriter->upsertBatch($rate->getId(), CriterionValueType::Decimal->valueColumn(), $batch);
                $batch = [];
            }
        }

        $this->criterionValueWriter->upsertBatch($rate->getId(), CriterionValueType::Decimal->valueColumn(), $batch);

        return \count($rows);
    }
}
