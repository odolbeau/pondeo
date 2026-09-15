<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\ZoneCriterionValue;
use App\Enum\CriterionValueType;
use App\Factory\CommuneFactory;
use App\Factory\CriterionFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class CommuneMapDataControllerTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    public function testDataEndpointOnlyReturnsCommunesWithinPopulationRange(): void
    {
        $client = self::createClient();

        $small = CommuneFactory::createOne(['inseeCode' => '00001', 'boundary' => 'SRID=4326;MULTIPOLYGON(((0 0,0 1,1 1,1 0,0 0)))']);
        $medium = CommuneFactory::createOne(['inseeCode' => '00002', 'boundary' => 'SRID=4326;MULTIPOLYGON(((1 0,1 1,2 1,2 0,1 0)))']);
        $large = CommuneFactory::createOne(['inseeCode' => '00003', 'boundary' => 'SRID=4326;MULTIPOLYGON(((2 0,2 1,3 1,3 0,2 0)))']);

        $criterion = CriterionFactory::createOne(['code' => 'population', 'valueType' => CriterionValueType::Integer]);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);

        foreach ([[$small, 500], [$medium, 5_000], [$large, 50_000]] as [$commune, $population]) {
            $entityManager->persist(ZoneCriterionValue::forZone($criterion, $commune, $population, 2023));
        }

        $entityManager->flush();

        $client->request('GET', '/carte/communes/donnees', ['populationMin' => 1_000, 'populationMax' => 10_000]);

        self::assertResponseIsSuccessful();
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);

        /** @var array{type: string, features: list<array{properties: array{code: string}}>} $data */
        $data = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame('FeatureCollection', $data['type']);
        self::assertCount(1, $data['features']);
        self::assertSame('00002', $data['features'][0]['properties']['code']);
    }
}
