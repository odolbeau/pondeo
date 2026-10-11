<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Enum\CriterionValueType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Imports the Filosofi income figures (see `docs/criteria-income.md`): the
 * median standard of living and the poverty rate. Neither is additive, so a
 * unité urbaine's value is its communes' values averaged with their
 * population as weight, over the communes that have a figure.
 */
final readonly class IncomeImporter
{
    public const string GROUP = 'Revenus';

    /**
     * Indexed by the CSV measure.
     *
     * @var array<string, array{code: string, label: string, type: CriterionValueType, unit: string, position: int}>
     */
    private const array CRITERIA = [
        'median' => ['code' => 'niveau_vie_median', 'label' => 'Niveau de vie médian', 'type' => CriterionValueType::Integer, 'unit' => '€/an', 'position' => 0],
        'poverty' => ['code' => 'taux_pauvrete', 'label' => 'Taux de pauvreté', 'type' => CriterionValueType::Decimal, 'unit' => '%', 'position' => 1],
    ];

    public function __construct(
        private CsvCriterionImporter $csvCriterionImporter,
        private CriterionValueWriter $criterionValueWriter,
        private CsvReader $csvReader,
        private Connection $connection,
    ) {
    }

    /**
     * @param string $populationCsvPath commune population per year, the weights
     *
     * @return int number of (unité urbaine, period) values upserted
     */
    public function import(string $incomeCsvPath, string $populationCsvPath): int
    {
        /** @var array<string, string> $uniteUrbaineIdsByCommuneCode */
        $uniteUrbaineIdsByCommuneCode = $this->connection->fetchAllKeyValue('SELECT insee_code, unite_urbaine_id FROM commune');

        /** @var array<string, int> $populations indexed by commune code and year */
        $populations = [];
        foreach ($this->csvReader->read($populationCsvPath) as $row) {
            if (!isset($uniteUrbaineIdsByCommuneCode[$row['code_insee']])) {
                continue; // Only the communes of an unité urbaine matter: keeps the map small.
            }

            $populations[$row['code_insee'].'|'.$row['year']] = (int) $row['population'];
        }

        /** @var array<string, array<string, array<int, array{weight: int, weighted: float}>>> $totals indexed by measure, unité urbaine id, then period */
        $totals = [];
        foreach ($this->csvReader->read($incomeCsvPath) as $row) {
            $uniteUrbaineId = $uniteUrbaineIdsByCommuneCode[$row['code_insee']] ?? null;
            $weight = $populations[$row['code_insee'].'|'.$row['year']] ?? 0;
            if (null === $uniteUrbaineId || !isset(self::CRITERIA[$row['measure']]) || $weight <= 0) {
                continue; // Commune outside every unité urbaine, unknown measure, or no population to weigh with.
            }

            $period = (int) $row['year'];
            $totals[$row['measure']][$uniteUrbaineId][$period] ??= ['weight' => 0, 'weighted' => 0.0];
            $totals[$row['measure']][$uniteUrbaineId][$period]['weight'] += $weight;
            $totals[$row['measure']][$uniteUrbaineId][$period]['weighted'] += $weight * (float) $row['value'];
        }

        $count = 0;
        foreach (self::CRITERIA as $measure => ['code' => $code, 'label' => $label, 'type' => $type, 'unit' => $unit, 'position' => $position]) {
            $criterionId = $this->csvCriterionImporter->findOrCreate($code, $label, $type, $unit, self::GROUP, position: $position)->getId();

            $batch = [];
            foreach ($totals[$measure] ?? [] as $uniteUrbaineId => $totalsByPeriod) {
                foreach ($totalsByPeriod as $period => $total) {
                    $average = $total['weighted'] / $total['weight'];
                    $batch[] = [
                        'id' => Uuid::v7(),
                        'uniteUrbaineId' => Uuid::fromString($uniteUrbaineId),
                        'period' => $period,
                        'value' => CriterionValueType::Integer === $type ? (int) round($average) : number_format($average, 2, '.', ''),
                    ];
                    ++$count;

                    if (\count($batch) >= CriterionValueWriter::BATCH_SIZE) {
                        $this->criterionValueWriter->upsertBatch($criterionId, $type->valueColumn(), $batch);
                        $batch = [];
                    }
                }
            }

            $this->criterionValueWriter->upsertBatch($criterionId, $type->valueColumn(), $batch);
        }

        return $count;
    }
}
