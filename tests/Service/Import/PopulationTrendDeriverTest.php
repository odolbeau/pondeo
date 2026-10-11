<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Enum\CriterionValueType;
use App\Factory\CriterionFactory;
use App\Factory\UniteUrbaineFactory;
use App\Service\Import\PopulationTrendDeriver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class PopulationTrendDeriverTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function testDeriveComputesGrowthAndDensityFromPopulation(): void
    {
        // About 124 km² (0.1° square on the equator).
        UniteUrbaineFactory::createOne(['inseeCode' => '56501', 'boundary' => 'SRID=4326;MULTIPOLYGON(((0 0,0.1 0,0.1 0.1,0 0.1,0 0)))']);
        UniteUrbaineFactory::createOne(['inseeCode' => '44701']);

        foreach ([2006 => 100_000, 2007 => 110_000, 2011 => 120_000, 2016 => 132_000] as $year => $population) {
            $this->createPopulation('56501', $year, $population);
        }

        $this->createPopulation('44701', 2006, 1_000);

        $deriver = self::getContainer()->get(PopulationTrendDeriver::class);
        \assert($deriver instanceof PopulationTrendDeriver);

        $deriver->derive();

        // 5 years: only where the population 5 years earlier is known.
        self::assertEqualsWithDelta(20.0, $this->decimal('56501', 'population_croissance_5_ans', 2011), 0.01);
        self::assertEqualsWithDelta(10.0, $this->decimal('56501', 'population_croissance_5_ans', 2016), 0.01);
        self::assertNull($this->decimal('56501', 'population_croissance_5_ans', 2007));

        self::assertEqualsWithDelta(1_064.0, $this->decimal('56501', 'densite', 2016), 15.0);

        // No boundary: no density. A single year gives no growth.
        self::assertNull($this->decimal('44701', 'densite', 2006));
        self::assertNull($this->decimal('44701', 'population_croissance_5_ans', 2006));

        // Idempotent: a second run updates in place.
        $deriver->derive();
        self::assertEqualsWithDelta(20.0, $this->decimal('56501', 'population_croissance_5_ans', 2011), 0.01);
    }

    private function createPopulation(string $uniteUrbaineCode, int $period, int $population): void
    {
        $criterion = CriterionFactory::findOrCreate(['code' => 'population', 'valueType' => CriterionValueType::Integer]);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);

        $entityManager->getConnection()->executeStatement(
            <<<'SQL'
                INSERT INTO criterion_value (id, criterion_id, unite_urbaine_id, period, value_integer, created_at, updated_at)
                SELECT gen_random_uuid(), :criterionId, uu.id, :period, :population, NOW(), NOW()
                FROM unite_urbaine uu WHERE uu.insee_code = :code
                SQL,
            ['criterionId' => $criterion->getId()->toRfc4122(), 'period' => $period, 'population' => $population, 'code' => $uniteUrbaineCode],
        );
    }

    // Read through DBAL: see CsvCriterionImporterTest::valuesByPeriod().
    private function decimal(string $uniteUrbaineCode, string $criterionCode, int $period): ?float
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);

        $value = $entityManager->getConnection()->fetchOne(
            <<<'SQL'
                SELECT cv.value_decimal
                FROM criterion_value cv
                INNER JOIN unite_urbaine uu ON uu.id = cv.unite_urbaine_id
                INNER JOIN criterion ON criterion.id = cv.criterion_id
                WHERE uu.insee_code = :code AND criterion.code = :criterionCode AND cv.period = :period
                SQL,
            ['code' => $uniteUrbaineCode, 'criterionCode' => $criterionCode, 'period' => $period],
        );

        return is_numeric($value) ? (float) $value : null;
    }
}
