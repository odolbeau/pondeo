<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CriterionValue;
use App\Enum\CriterionValueType;
use App\Factory\CriterionFactory;
use App\Factory\UniteUrbaineFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class UniteUrbaineMapControllerTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    public function testMapPageReturns200(): void
    {
        $client = self::createClient();

        $uniteUrbaine = UniteUrbaineFactory::createOne();
        $criterion = CriterionFactory::createOne(['code' => 'population', 'valueType' => CriterionValueType::Integer]);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);
        $entityManager->persist(new CriterionValue($criterion, $uniteUrbaine, 84_176, 2023));
        $entityManager->flush();

        $client->request('GET', '/carte');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-controller="map"]');
        self::assertSelectorExists('[data-map-detail-url-value="/unites-urbaines/XXXXX"]');
        self::assertSelectorExists('aside.hidden turbo-frame#unite-urbaine-detail');
        self::assertSelectorExists('input[type="checkbox"][data-filters-target="excludeCouronne"]:not([checked])');
        self::assertSelectorExists('input[type="checkbox"][data-filters-target="excludePolesSecondaires"]:not([checked])');
        self::assertSelectorExists('input[type="number"][data-filters-target="minInput"][value="84176"]');
        self::assertSelectorExists('input[type="number"][data-filters-target="maxInput"][value="84176"]');
        self::assertSelectorExists('input[type="number"][data-filters-target="priceMax"]:not([value])');
        self::assertSelectorExists('input[type="number"][data-filters-target="restaurantDensityMin"]:not([value])');
        self::assertSelectorCount(3, 'button[data-filters-target="priceTypeButton"]');
        self::assertSelectorExists('button[data-price-type="global"][aria-pressed="true"]');
        self::assertSelectorExists('aside[data-map-target="filters"][data-controller="filters"][data-filters-population-min-value="84176"][data-filters-population-max-value="84176"]');
        self::assertSelectorExists('aside [data-filters-target="summary"][hidden]');
        self::assertSelectorExists('[data-map-export-url-value="/carte/export.csv"][data-map-export-limit-value="200"]');
        self::assertSelectorExists('button[data-map-target="exportButton"][disabled]');
        self::assertSelectorExists('button[data-action="map#openFilters"]');
    }
}
