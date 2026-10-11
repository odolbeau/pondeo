<?php

declare(strict_types=1);

namespace App\Tests\Service\Detail;

use App\Entity\CriterionValue;
use App\Enum\CriterionValueType;
use App\Factory\CriterionFactory;
use App\Factory\UniteUrbaineFactory;
use App\Service\Detail\UniteUrbaineCriteriaProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class UniteUrbaineCriteriaProviderTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function testGroupsCriteriaAndComparesAgainstOtherUnitesUrbaines(): void
    {
        $small = UniteUrbaineFactory::createOne(['inseeCode' => '00001']);
        $medium = UniteUrbaineFactory::createOne(['inseeCode' => '00002']);
        $large = UniteUrbaineFactory::createOne(['inseeCode' => '00003']);

        $population = CriterionFactory::createOne(['code' => 'population', 'label' => 'Population', 'unit' => 'hab.', 'valueType' => CriterionValueType::Integer, 'groupLabel' => 'Démographie']);
        $label = CriterionFactory::createOne(['code' => 'label', 'label' => 'Label', 'valueType' => CriterionValueType::Text]);
        $rain = CriterionFactory::createOne(['code' => 'rain', 'label' => 'Pluie', 'valueType' => CriterionValueType::Decimal, 'groupLabel' => 'Climat']);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);
        $values = [
            [$population, $small, 100, 2023],
            [$population, $medium, 150, 2022],
            [$population, $medium, 200, 2023],
            [$population, $large, 300, 2023],
            [$label, $medium, 'Ville moyenne', 2023],
            // Only the unit's own latest period counts: this older value of
            // another unit must not leak into the comparison.
            [$rain, $medium, 110.5, 2023],
            [$rain, $large, 90.5, 2023],
            [$rain, $small, 1.0, 2019],
        ];
        foreach ($values as [$criterion, $uniteUrbaine, $value, $period]) {
            $entityManager->persist(new CriterionValue($criterion, $uniteUrbaine, $value, $period));
        }

        $entityManager->flush();
        $entityManager->clear();

        $provider = new UniteUrbaineCriteriaProvider($entityManager->getConnection());
        $mediumUnit = $entityManager->getRepository($medium::class)->findOneBy(['inseeCode' => '00002']);
        \assert(null !== $mediumUnit);

        $groups = $provider->findGroups($mediumUnit);

        self::assertSame(['Démographie', 'Climat', 'Autres'], array_map(static fn (\App\Service\Detail\CriterionGroupDetail $group): string => $group->label, $groups));

        [$demography, $climate, $others] = $groups;

        $rainDetail = $climate->criteria[0];
        self::assertSame(110.5, $rainDetail->value);
        self::assertSame(1, $rainDetail->rank);
        self::assertSame(2, $rainDetail->total);
        self::assertSame(100.5, $rainDetail->median);

        $populationDetail = $demography->criteria[0];
        self::assertSame(200, $populationDetail->value);
        self::assertSame(2023, $populationDetail->period);
        self::assertSame([2022 => 150, 2023 => 200], $populationDetail->series);
        self::assertSame(2, $populationDetail->rank);
        self::assertSame(3, $populationDetail->total);
        self::assertSame(200.0, $populationDetail->median);

        $labelDetail = $others->criteria[0];
        self::assertSame('Ville moyenne', $labelDetail->value);
        self::assertNull($labelDetail->rank);
        self::assertSame([], $labelDetail->series);
    }

    public function testRealEstateGroupComesAfterDemographyAndRanksTheMostExpensiveFirst(): void
    {
        $paris = UniteUrbaineFactory::createOne(['inseeCode' => '00001']);
        $vannes = UniteUrbaineFactory::createOne(['inseeCode' => '00002']);
        $brest = UniteUrbaineFactory::createOne(['inseeCode' => '00003']);

        $population = CriterionFactory::createOne(['code' => 'population', 'label' => 'Population', 'valueType' => CriterionValueType::Integer, 'groupLabel' => 'Démographie']);
        $price = CriterionFactory::createOne(['code' => 'prix_m2', 'label' => 'Prix au m²', 'unit' => '€/m²', 'valueType' => CriterionValueType::Integer, 'groupLabel' => 'Immobilier']);
        $shops = CriterionFactory::createOne(['code' => 'shops', 'label' => 'Commerces', 'valueType' => CriterionValueType::Decimal, 'groupLabel' => 'Commerces']);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);
        foreach ([
            [$population, $vannes, 56_000, 2023],
            [$shops, $vannes, 5.5, 2025],
            [$price, $paris, 10_500, 2025],
            [$price, $vannes, 3_600, 2024],
            [$price, $vannes, 3_800, 2025],
            [$price, $brest, 2_000, 2025],
        ] as [$criterion, $uniteUrbaine, $value, $period]) {
            $entityManager->persist(new CriterionValue($criterion, $uniteUrbaine, $value, $period));
        }

        $entityManager->flush();
        $entityManager->clear();

        $provider = new UniteUrbaineCriteriaProvider($entityManager->getConnection());
        $vannesUnit = $entityManager->getRepository($vannes::class)->findOneBy(['inseeCode' => '00002']);
        \assert(null !== $vannesUnit);

        $groups = $provider->findGroups($vannesUnit);

        self::assertSame(['Démographie', 'Immobilier', 'Commerces'], array_map(static fn (\App\Service\Detail\CriterionGroupDetail $group): string => $group->label, $groups));

        $priceDetail = $groups[1]->criteria[0];
        self::assertSame(3_800, $priceDetail->value);
        self::assertSame([2024 => 3_600, 2025 => 3_800], $priceDetail->series);
        self::assertSame(2, $priceDetail->rank);
        self::assertSame(3, $priceDetail->total);
        self::assertSame(3_800.0, $priceDetail->median);
    }
}
