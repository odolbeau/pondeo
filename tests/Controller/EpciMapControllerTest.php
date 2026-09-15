<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ZoneCriterionValue;
use App\Enum\CriterionValueType;
use App\Factory\CriterionFactory;
use App\Factory\EpciFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class EpciMapControllerTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    public function testEpciMapPageReturns200(): void
    {
        $client = self::createClient();

        $epci = EpciFactory::createOne();
        $criterion = CriterionFactory::createOne(['code' => 'population', 'valueType' => CriterionValueType::Integer]);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);
        $entityManager->persist(ZoneCriterionValue::forZone($criterion, $epci, 1_000, 2023));
        $entityManager->flush();

        $client->request('GET', '/carte/epci');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-controller="map"]');
    }
}
