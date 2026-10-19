<?php

declare(strict_types=1);

namespace App\Tests\Service\Map;

use App\Entity\CriterionValue;
use App\Enum\CriterionValueType;
use App\Factory\CriterionFactory;
use App\Factory\UniteUrbaineFactory;
use App\Service\Map\CriterionStatsProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class CriterionStatsProviderTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function testItComputesQuantilesOfTheLatestPeriod(): void
    {
        $criterion = CriterionFactory::createOne(['code' => 'prix_m2', 'valueType' => CriterionValueType::Integer]);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);
        foreach ([1_000, 2_000, 3_000, 4_000, 5_000] as $i => $price) {
            $uniteUrbaine = UniteUrbaineFactory::createOne(['inseeCode' => \sprintf('%05d', $i)]);
            // An older period must be ignored.
            $entityManager->persist(new CriterionValue($criterion, $uniteUrbaine, 99_999, 2024));
            $entityManager->persist(new CriterionValue($criterion, $uniteUrbaine, $price, 2025));
        }

        $entityManager->flush();

        $provider = self::getContainer()->get(CriterionStatsProvider::class);
        \assert($provider instanceof CriterionStatsProvider);
        $stats = $provider->findStats(['prix_m2', 'unknown']);

        self::assertSame(['prix_m2'], array_keys($stats));
        self::assertEqualsWithDelta(1_000, $stats['prix_m2']['min'], 0.001);
        self::assertEqualsWithDelta(2_000, $stats['prix_m2']['p25'], 0.001);
        self::assertEqualsWithDelta(3_000, $stats['prix_m2']['median'], 0.001);
        self::assertEqualsWithDelta(4_000, $stats['prix_m2']['p75'], 0.001);
        self::assertEqualsWithDelta(5_000, $stats['prix_m2']['max'], 0.001);
    }
}
