<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Enum\CriterionValueType;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Imports a ratio per unité urbaine from commune counts, a share in % unless
 * told otherwise: the counts are additive, so they are summed per unité
 * urbaine like any criterion, and the ratios are derived from those sums,
 * never summed. The counts are only a means to the ratios and are dropped once
 * these are derived.
 */
final readonly class CountRatioImporter
{
    public function __construct(
        private CsvCriterionImporter $csvCriterionImporter,
        private CriterionValueWriter $criterionValueWriter,
        private Connection $connection,
    ) {
    }

    /**
     * @param array<string, string>                                                                                                                $counts criterion label indexed by the CSV status it is read from; the criterion code is `count_<status>`
     * @param list<array{code: string, label: string, numerator: string, denominator: string, unit?: string, factor?: int, rankFromLowest?: bool}> $shares numerator and denominator are CSV statuses, the list order is the display order; a ratio is multiplied by `factor` (100 for a share in %, the default) and shown with `unit` ("%" by default), and ranked from the lowest value when `rankFromLowest` is set
     *
     * @return int number of (unité urbaine, period) share values upserted
     */
    public function import(string $csvPath, string $group, array $counts, array $shares): int
    {
        $codes = [];
        foreach ($counts as $status => $label) {
            $codes[$status] = 'count_'.$status;
            $this->csvCriterionImporter->import(new CriterionCsvSpec(
                code: $codes[$status],
                label: $label,
                valueType: CriterionValueType::Integer,
                unit: null,
                communeCodeColumn: 'code_insee',
                periodColumn: 'year',
                valueColumn: 'count',
                groupLabel: $group,
                filterColumn: 'status',
                filterValue: $status,
                comparable: false,
            ), $csvPath);
        }

        $count = 0;
        foreach ($shares as $position => $share) {
            $count += $this->deriveShare($share['code'], $share['label'], $group, $codes[$share['numerator']], $codes[$share['denominator']], $position, $share['unit'] ?? '%', $share['factor'] ?? 100, $share['rankFromLowest'] ?? false);
        }

        $this->connection->executeStatement('DELETE FROM criterion_value WHERE criterion_id IN (SELECT id FROM criterion WHERE code IN (:codes))', ['codes' => array_values($codes)], ['codes' => ArrayParameterType::STRING]);
        $this->connection->executeStatement('DELETE FROM criterion WHERE code IN (:codes)', ['codes' => array_values($codes)], ['codes' => ArrayParameterType::STRING]);

        return $count;
    }

    private function deriveShare(string $code, string $label, string $group, string $numeratorCode, string $denominatorCode, int $position, string $unit, int $factor, bool $rankFromLowest): int
    {
        $share = $this->csvCriterionImporter->findOrCreate($code, $label, CriterionValueType::Decimal, $unit, $group, position: $position, rankFromLowest: $rankFromLowest);

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
                'value' => number_format((int) (is_numeric($row['numerator']) ? $row['numerator'] : 0) / (int) (is_numeric($row['denominator']) ? $row['denominator'] : 1) * $factor, 2, '.', ''),
            ];

            if (\count($batch) >= CriterionValueWriter::BATCH_SIZE) {
                $this->criterionValueWriter->upsertBatch($share->getId(), CriterionValueType::Decimal->valueColumn(), $batch);
                $batch = [];
            }
        }

        $this->criterionValueWriter->upsertBatch($share->getId(), CriterionValueType::Decimal->valueColumn(), $batch);

        return \count($rows);
    }
}
