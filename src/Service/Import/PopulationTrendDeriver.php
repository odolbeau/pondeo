<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Enum\CriterionValueType;
use App\Repository\CriterionRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Derives the demographic dynamics (see `docs/criteria-population.md`) from
 * the stored population series and the unité urbaine boundaries: growth over
 * 5 years and density. These are
 * ratios, so they are computed from the stored population, never summed.
 */
final readonly class PopulationTrendDeriver
{
    public const string GROUP = 'Démographie';

    private const int GROWTH_WINDOW = 5;

    public function __construct(
        private CsvCriterionImporter $csvCriterionImporter,
        private CriterionValueWriter $criterionValueWriter,
        private CriterionRepository $criterionRepository,
        private Connection $connection,
    ) {
    }

    /**
     * @return int number of (unité urbaine, period) values upserted
     */
    public function derive(): int
    {
        $populationId = $this->criterionRepository->findOneBy(['code' => 'population'])?->getId();
        if (null === $populationId) {
            throw new \LogicException('Criterion "population" not found: import it first.');
        }

        $growth = $this->csvCriterionImporter->findOrCreate('population_croissance_5_ans', 'Évolution sur 5 ans', CriterionValueType::Decimal, '%', self::GROUP, position: 1)->getId();
        $density = $this->csvCriterionImporter->findOrCreate('densite', 'Densité', CriterionValueType::Decimal, 'hab./km²', self::GROUP, position: 2)->getId();

        /** @var list<array{unite_urbaine_id: string, period: int, population: int, area_km2: string|float|null}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT cv.unite_urbaine_id, cv.period, cv.value_integer AS population,
                       ST_Area(uu.boundary::geography) / 1000000 AS area_km2
                FROM criterion_value cv
                INNER JOIN unite_urbaine uu ON uu.id = cv.unite_urbaine_id
                WHERE cv.criterion_id = :populationId AND cv.value_integer > 0
                ORDER BY cv.unite_urbaine_id, cv.period
                SQL,
            ['populationId' => $populationId->toRfc4122()],
        );

        /** @var array<string, array{area: float, population: array<int, int>}> $units */
        $units = [];
        foreach ($rows as $row) {
            $units[$row['unite_urbaine_id']]['area'] = (float) $row['area_km2'];
            $units[$row['unite_urbaine_id']]['population'][(int) $row['period']] = (int) $row['population'];
        }

        /** @var array{growth: list<array{id: Uuid, uniteUrbaineId: Uuid, period: int, value: string}>, density: list<array{id: Uuid, uniteUrbaineId: Uuid, period: int, value: string}>} $batches */
        $batches = ['growth' => [], 'density' => []];
        $count = 0;

        foreach ($units as $uniteUrbaineId => ['area' => $area, 'population' => $series]) {
            $uuid = Uuid::fromString($uniteUrbaineId);
            foreach ($series as $period => $population) {
                if (isset($series[$period - self::GROWTH_WINDOW])) {
                    $batches['growth'][] = $this->row($uuid, $period, ($population / $series[$period - self::GROWTH_WINDOW] - 1) * 100);
                }

                if ($area > 0) {
                    $batches['density'][] = $this->row($uuid, $period, $population / $area);
                }
            }
        }

        foreach (['growth' => $growth, 'density' => $density] as $key => $criterionId) {
            foreach (array_chunk($batches[$key], CriterionValueWriter::BATCH_SIZE) as $chunk) {
                $this->criterionValueWriter->upsertBatch($criterionId, CriterionValueType::Decimal->valueColumn(), $chunk);
            }

            $count += \count($batches[$key]);
        }

        return $count;
    }

    /**
     * @return array{id: Uuid, uniteUrbaineId: Uuid, period: int, value: string}
     */
    private function row(Uuid $uniteUrbaineId, int $period, float $value): array
    {
        return [
            'id' => Uuid::v7(),
            'uniteUrbaineId' => $uniteUrbaineId,
            'period' => $period,
            'value' => number_format($value, 2, '.', ''),
        ];
    }
}
