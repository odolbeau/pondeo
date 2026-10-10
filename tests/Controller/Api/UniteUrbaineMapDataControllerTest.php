<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\CriterionValue;
use App\Enum\CriterionValueType;
use App\Factory\CriterionFactory;
use App\Factory\UniteUrbaineFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class UniteUrbaineMapDataControllerTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    public function testDataEndpointOnlyReturnsUnitesUrbainesWithinPopulationRange(): void
    {
        $client = self::createClient();

        $small = UniteUrbaineFactory::createOne(['inseeCode' => '00001', 'boundary' => 'SRID=4326;MULTIPOLYGON(((0 0,0 1,1 1,1 0,0 0)))']);
        $medium = UniteUrbaineFactory::createOne(['inseeCode' => '00002', 'boundary' => 'SRID=4326;MULTIPOLYGON(((1 0,1 1,2 1,2 0,1 0)))']);
        $large = UniteUrbaineFactory::createOne(['inseeCode' => '00003', 'boundary' => 'SRID=4326;MULTIPOLYGON(((2 0,2 1,3 1,3 0,2 0)))']);

        $criterion = CriterionFactory::createOne(['code' => 'population', 'valueType' => CriterionValueType::Integer]);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);

        foreach ([[$small, 5_000], [$medium, 50_000], [$large, 500_000]] as [$uniteUrbaine, $population]) {
            $entityManager->persist(new CriterionValue($criterion, $uniteUrbaine, $population, 2023));
        }

        $entityManager->flush();

        $client->request('GET', '/carte/donnees', ['populationMin' => 10_000, 'populationMax' => 100_000]);

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
