<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Entity\ZoneCriterionValue;
use App\Enum\CriterionValueType;
use App\Enum\ZoneType;
use App\Factory\CommuneFactory;
use App\Factory\CriterionFactory;
use App\Factory\DepartementFactory;
use App\Factory\EpciFactory;
use App\Factory\RegionFactory;
use App\Service\Import\CriterionAggregator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class CriterionAggregatorTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function testAggregateSumsMemberCommunesPerPeriod(): void
    {
        $region = RegionFactory::createOne();
        $departement = DepartementFactory::createOne(['region' => $region]);
        $epci = EpciFactory::createOne();

        // c1 and c2 belong to the EPCI; c3 doesn't (must not contribute to
        // the EPCI aggregate, but still counts for département/région).
        $c1 = CommuneFactory::createOne(['departement' => $departement, 'region' => $region, 'epci' => $epci]);
        $c2 = CommuneFactory::createOne(['departement' => $departement, 'region' => $region, 'epci' => $epci]);
        $c3 = CommuneFactory::createOne(['departement' => $departement, 'region' => $region, 'epci' => null]);

        $criterion = CriterionFactory::createOne(['code' => 'test_population', 'valueType' => CriterionValueType::Integer]);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);

        foreach ([
            [$c1, 100, 2020], [$c1, 110, 2021],
            [$c2, 200, 2020], [$c2, 210, 2021],
            [$c3, 50, 2020], // no 2021 value for c3
        ] as [$commune, $value, $period]) {
            $entityManager->persist(ZoneCriterionValue::forZone($criterion, $commune, $value, $period));
        }

        $entityManager->flush();

        $aggregator = self::getContainer()->get(CriterionAggregator::class);
        \assert($aggregator instanceof CriterionAggregator);

        $epciCount = $aggregator->aggregate('test_population', ZoneType::Epci);
        self::assertSame(2, $epciCount);
        self::assertSame([2020 => 300, 2021 => 320], $this->valuesByPeriod('epci', $epci->getId()->toRfc4122(), 'test_population'));

        $departementCount = $aggregator->aggregate('test_population', ZoneType::Departement);
        self::assertSame(2, $departementCount);
        self::assertSame([2020 => 350, 2021 => 320], $this->valuesByPeriod('departement', $departement->getId()->toRfc4122(), 'test_population'));

        $regionCount = $aggregator->aggregate('test_population', ZoneType::Region);
        self::assertSame(2, $regionCount);
        self::assertSame([2020 => 350, 2021 => 320], $this->valuesByPeriod('region', $region->getId()->toRfc4122(), 'test_population'));
    }

    public function testAggregateIsIdempotentAndUpdatesInPlace(): void
    {
        $region = RegionFactory::createOne();
        $departement = DepartementFactory::createOne(['region' => $region]);
        $epci = EpciFactory::createOne();
        $c1 = CommuneFactory::createOne(['departement' => $departement, 'region' => $region, 'epci' => $epci]);
        $c2 = CommuneFactory::createOne(['departement' => $departement, 'region' => $region, 'epci' => $epci]);

        $criterion = CriterionFactory::createOne(['code' => 'test_population_2', 'valueType' => CriterionValueType::Integer]);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);

        $c1Value = ZoneCriterionValue::forZone($criterion, $c1, 100, 2020);
        $entityManager->persist($c1Value);
        $entityManager->persist(ZoneCriterionValue::forZone($criterion, $c2, 200, 2020));
        $entityManager->flush();

        $aggregator = self::getContainer()->get(CriterionAggregator::class);
        \assert($aggregator instanceof CriterionAggregator);

        self::assertSame(1, $aggregator->aggregate('test_population_2', ZoneType::Epci));
        self::assertSame([2020 => 300], $this->valuesByPeriod('epci', $epci->getId()->toRfc4122(), 'test_population_2'));

        $c1Value->setValue(999);
        $entityManager->flush();

        self::assertSame(1, $aggregator->aggregate('test_population_2', ZoneType::Epci));
        self::assertSame([2020 => 1199], $this->valuesByPeriod('epci', $epci->getId()->toRfc4122(), 'test_population_2'));
    }

    /**
     * @return array<int, int>
     */
    private function valuesByPeriod(string $zoneTable, string $zoneId, string $criterionCode): array
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);
        $connection = $entityManager->getConnection();

        /** @var list<array{period: int, value_integer: int}> $rows */
        $rows = $connection->fetchAllAssociative(
            \sprintf(
                <<<'SQL'
                    SELECT zcv.period, zcv.value_integer
                    FROM zone_criterion_value zcv
                    INNER JOIN criterion ON criterion.id = zcv.criterion_id
                    WHERE zcv.%1$s_id = :zoneId AND criterion.code = :criterionCode
                    SQL,
                $zoneTable,
            ),
            ['zoneId' => $zoneId, 'criterionCode' => $criterionCode],
        );

        $byPeriod = [];
        foreach ($rows as $row) {
            $byPeriod[$row['period']] = $row['value_integer'];
        }

        return $byPeriod;
    }
}
