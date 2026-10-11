<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Enum\CriterionValueType;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Imports a share (in %) per unité urbaine from commune counts: the counts
 * are additive, so they are summed per unité urbaine like any criterion, and
 * the shares are derived from those sums, never summed. The counts are only a
 * means to the shares and are dropped once these are derived.
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
     * @param array<string, string>                                                            $counts criterion label indexed by the CSV status it is read from; the criterion code is `count_<status>`
     * @param list<array{code: string, label: string, numerator: string, denominator: string}> $shares numerator and denominator are CSV statuses; the list order is the display order
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
        foreach ($shares as $position => ['code' => $code, 'label' => $label, 'numerator' => $numerator, 'denominator' => $denominator]) {
            $count += $this->deriveShare($code, $label, $group, $codes[$numerator], $codes[$denominator], $position);
        }

        $this->connection->executeStatement('DELETE FROM criterion_value WHERE criterion_id IN (SELECT id FROM criterion WHERE code IN (:codes))', ['codes' => array_values($codes)], ['codes' => ArrayParameterType::STRING]);
        $this->connection->executeStatement('DELETE FROM criterion WHERE code IN (:codes)', ['codes' => array_values($codes)], ['codes' => ArrayParameterType::STRING]);

        return $count;
    }

    private function deriveShare(string $code, string $label, string $group, string $numeratorCode, string $denominatorCode, int $position): int
    {
        $share = $this->csvCriterionImporter->findOrCreate($code, $label, CriterionValueType::Decimal, '%', $group, position: $position);

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
                $this->criterionValueWriter->upsertBatch($share->getId(), CriterionValueType::Decimal->valueColumn(), $batch);
                $batch = [];
            }
        }

        $this->criterionValueWriter->upsertBatch($share->getId(), CriterionValueType::Decimal->valueColumn(), $batch);

        return \count($rows);
    }
}
