<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ZoneCriterionValue;
use App\Enum\CriterionValueType;
use App\Factory\CommuneFactory;
use App\Factory\CriterionFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class CommuneMapControllerTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    public function testCommuneMapPageReturns200(): void
    {
        $client = self::createClient();

        $commune = CommuneFactory::createOne();
        $criterion = CriterionFactory::createOne(['code' => 'population', 'valueType' => CriterionValueType::Integer]);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);
        $entityManager->persist(ZoneCriterionValue::forZone($criterion, $commune, 1_000, 2023));
        $entityManager->flush();

        $client->request('GET', '/carte/communes');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-controller="map"]');
    }
}
