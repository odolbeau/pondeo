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
        self::assertSelectorExists('input[type="number"][data-map-target="minInput"][value="84176"]');
        self::assertSelectorExists('input[type="number"][data-map-target="maxInput"][value="84176"]');
    }
}
