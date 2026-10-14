<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Enum\CriterionValueType;
use App\Repository\CriterionRepository;
use App\Service\Detail\UniteUrbaineCriteriaProvider;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Derives the density per 1000 inhabitants of an already imported count
 * criterion, stored as the `<count code>_pour_1000_hab` criterion. Densities
 * come from the stored counts and population, never from summing communes.
 */
final readonly class DensityPerThousandDeriver
{
    public function __construct(
        private CsvCriterionImporter $csvCriterionImporter,
        private CriterionValueWriter $criterionValueWriter,
        private CriterionRepository $criterionRepository,
        private Connection $connection,
    ) {
    }

    /**
     * @return int number of (unité urbaine, period) densities upserted
     */
    public function derive(string $countCode, string $label, string $group, int $position): int
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
            $group,
            position: $position,
        );

        // The population of the latest year not after the count's: the census
        // series ends before the counts do.
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT n.unite_urbaine_id, n.period, n.value_integer AS items, p.value_integer AS population
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
                'value' => number_format((int) (is_numeric($row['items']) ? $row['items'] : 0) / (int) (is_numeric($row['population']) ? $row['population'] : 1) * 1000, 2, '.', ''),
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
