<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Enum\CriterionValueType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Imports the real estate prices (see `docs/criteria-real-estate.md`): one
 * price per m² criterion per property type. A price is not additive, so a
 * unité urbaine's price is its communes' prices averaged with their number of
 * sales as weight, never summed like {@see CsvCriterionImporter} does.
 */
final readonly class RealEstatePriceImporter
{
    public const string GROUP = 'Immobilier';

    /**
     * Indexed by the CSV property type.
     *
     * @var array<string, array{code: string, label: string, position: int}>
     */
    public const array CRITERIA = [
        'apt_maison' => ['code' => 'prix_m2', 'label' => 'Prix au m²', 'position' => 0],
        'appartement' => ['code' => 'prix_m2_appartement', 'label' => 'Prix au m² (appartements)', 'position' => 1],
        'maison' => ['code' => 'prix_m2_maison', 'label' => 'Prix au m² (maisons)', 'position' => 2],
    ];

    public function __construct(
        private CsvCriterionImporter $csvCriterionImporter,
        private CriterionValueWriter $criterionValueWriter,
        private CsvReader $csvReader,
        private Connection $connection,
    ) {
    }

    /**
     * @return int number of (unité urbaine, period) values upserted
     */
    public function import(string $csvPath): int
    {
        /** @var array<string, string> $uniteUrbaineIdsByCommuneCode */
        $uniteUrbaineIdsByCommuneCode = $this->connection->fetchAllKeyValue('SELECT insee_code, unite_urbaine_id FROM commune');

        /** @var array<string, array<string, array<int, array{sales: int, weighted: float}>>> $totals indexed by type, unité urbaine id, then period */
        $totals = [];
        foreach ($this->csvReader->read($csvPath) as $row) {
            $uniteUrbaineId = $uniteUrbaineIdsByCommuneCode[$row['code_insee']] ?? null;
            if (null === $uniteUrbaineId || !isset(self::CRITERIA[$row['type']])) {
                continue; // Commune outside every unité urbaine, or unknown type.
            }

            $sales = (int) $row['nb_ventes'];
            $period = (int) $row['year'];
            $totals[$row['type']][$uniteUrbaineId][$period] ??= ['sales' => 0, 'weighted' => 0.0];
            $totals[$row['type']][$uniteUrbaineId][$period]['sales'] += $sales;
            $totals[$row['type']][$uniteUrbaineId][$period]['weighted'] += $sales * (float) $row['prix_m2'];
        }

        $count = 0;
        foreach (self::CRITERIA as $type => ['code' => $code, 'label' => $label, 'position' => $position]) {
            $criterionId = $this->csvCriterionImporter->findOrCreate($code, $label, CriterionValueType::Integer, '€/m²', self::GROUP, position: $position)->getId();

            $batch = [];
            foreach ($totals[$type] ?? [] as $uniteUrbaineId => $totalsByPeriod) {
                foreach ($totalsByPeriod as $period => $total) {
                    if ($total['sales'] <= 0) {
                        continue;
                    }

                    $batch[] = [
                        'id' => Uuid::v7(),
                        'uniteUrbaineId' => Uuid::fromString($uniteUrbaineId),
                        'period' => $period,
                        'value' => (int) round($total['weighted'] / $total['sales']),
                    ];
                    ++$count;

                    if (\count($batch) >= CriterionValueWriter::BATCH_SIZE) {
                        $this->criterionValueWriter->upsertBatch($criterionId, CriterionValueType::Integer->valueColumn(), $batch);
                        $batch = [];
                    }
                }
            }

            $this->criterionValueWriter->upsertBatch($criterionId, CriterionValueType::Integer->valueColumn(), $batch);
        }

        return $count;
    }
}
