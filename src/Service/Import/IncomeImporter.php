<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Enum\CriterionValueType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Imports the Filosofi income figures (see `docs/criteria-income.md`): the
 * median standard of living and the poverty rate, which INSEE publishes for
 * each unité urbaine itself, so they are stored as is.
 */
final readonly class IncomeImporter
{
    public const string GROUP = 'Revenus';

    /**
     * Indexed by the CSV measure.
     *
     * @var array<string, array{code: string, label: string, type: CriterionValueType, unit: string, position: int, rankFromLowest: bool}>
     */
    private const array CRITERIA = [
        'median' => ['code' => 'niveau_vie_median', 'label' => 'Niveau de vie médian', 'type' => CriterionValueType::Integer, 'unit' => '€/an', 'position' => 0, 'rankFromLowest' => false],
        'poverty' => ['code' => 'taux_pauvrete', 'label' => 'Taux de pauvreté', 'type' => CriterionValueType::Decimal, 'unit' => '%', 'position' => 1, 'rankFromLowest' => true],
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
        /** @var array<string, string> $uniteUrbaineIdsByCode */
        $uniteUrbaineIdsByCode = $this->connection->fetchAllKeyValue('SELECT insee_code, id FROM unite_urbaine');

        /** @var array<string, list<array{id: Uuid, uniteUrbaineId: Uuid, period: int, value: int|string}>> $batches indexed by measure */
        $batches = [];
        foreach ($this->csvReader->read($csvPath) as $row) {
            $uniteUrbaineId = $uniteUrbaineIdsByCode[$row['code_unite_urbaine']] ?? null;
            $criterion = self::CRITERIA[$row['measure']] ?? null;
            if (null === $uniteUrbaineId || null === $criterion) {
                continue; // An unité urbaine we do not have, or an unknown measure.
            }

            $batches[$row['measure']][] = [
                'id' => Uuid::v7(),
                'uniteUrbaineId' => Uuid::fromString($uniteUrbaineId),
                'period' => (int) $row['year'],
                'value' => CriterionValueType::Integer === $criterion['type'] ? (int) round((float) $row['value']) : number_format((float) $row['value'], 2, '.', ''),
            ];
        }

        $count = 0;
        foreach (self::CRITERIA as $measure => ['code' => $code, 'label' => $label, 'type' => $type, 'unit' => $unit, 'position' => $position, 'rankFromLowest' => $rankFromLowest]) {
            $criterionId = $this->csvCriterionImporter->findOrCreate($code, $label, $type, $unit, self::GROUP, position: $position, rankFromLowest: $rankFromLowest)->getId();

            foreach (array_chunk($batches[$measure] ?? [], CriterionValueWriter::BATCH_SIZE) as $chunk) {
                $this->criterionValueWriter->upsertBatch($criterionId, $type->valueColumn(), $chunk);
            }

            $count += \count($batches[$measure] ?? []);
        }

        return $count;
    }
}
