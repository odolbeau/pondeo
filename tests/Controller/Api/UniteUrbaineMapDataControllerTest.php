<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\CriterionValue;
use App\Enum\AttractionCategory;
use App\Enum\CriterionValueType;
use App\Factory\CriterionFactory;
use App\Factory\PoiCategoryFactory;
use App\Factory\PoiFactory;
use App\Factory\UniteUrbaineFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
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

    public function testDataEndpointCanExcludeSatellitesAndSecondaryPoles(): void
    {
        $client = self::createClient();

        $pole = UniteUrbaineFactory::createOne(['inseeCode' => '00001', 'boundary' => 'SRID=4326;MULTIPOLYGON(((0 0,0 1,1 1,1 0,0 0)))']);
        $pole->setAttraction(AttractionCategory::CommuneCentre, '057', 'Vannes');

        $satellite = UniteUrbaineFactory::createOne(['inseeCode' => '00002', 'boundary' => 'SRID=4326;MULTIPOLYGON(((1 0,1 1,2 1,2 0,1 0)))']);
        $satellite->setAttraction(AttractionCategory::Couronne, '057', 'Vannes');

        $secondary = UniteUrbaineFactory::createOne(['inseeCode' => '00003', 'boundary' => 'SRID=4326;MULTIPOLYGON(((2 0,2 1,3 1,3 0,2 0)))']);
        $secondary->setAttraction(AttractionCategory::PoleSecondaire, '057', 'Vannes');
        // Unknown to the zoning: never excluded.
        $unknown = UniteUrbaineFactory::createOne(['inseeCode' => '00004', 'boundary' => 'SRID=4326;MULTIPOLYGON(((3 0,3 1,4 1,4 0,3 0)))']);

        $criterion = CriterionFactory::createOne(['code' => 'population', 'valueType' => CriterionValueType::Integer]);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);
        foreach ([$pole, $satellite, $secondary, $unknown] as $uniteUrbaine) {
            $entityManager->persist(new CriterionValue($criterion, $uniteUrbaine, 10_000, 2023));
        }

        $entityManager->flush();

        self::assertSame(['00001', '00002', '00003', '00004'], $this->codes($client, []));
        self::assertSame(['00001', '00003', '00004'], $this->codes($client, ['excludeCouronne' => '1']));
        self::assertSame(['00001', '00002', '00004'], $this->codes($client, ['excludePolesSecondaires' => '1']));
        self::assertSame(['00001', '00004'], $this->codes($client, ['excludeCouronne' => '1', 'excludePolesSecondaires' => '1']));
    }

    public function testDataEndpointCanRequireAMinimumCommerceDensity(): void
    {
        $client = self::createClient();

        $dense = UniteUrbaineFactory::createOne(['inseeCode' => '00001', 'boundary' => 'SRID=4326;MULTIPOLYGON(((0 0,0 1,1 1,1 0,0 0)))']);
        $sparse = UniteUrbaineFactory::createOne(['inseeCode' => '00002', 'boundary' => 'SRID=4326;MULTIPOLYGON(((1 0,1 1,2 1,2 0,1 0)))']);
        // No density value: cannot be said to reach a minimum.
        $unknown = UniteUrbaineFactory::createOne(['inseeCode' => '00003', 'boundary' => 'SRID=4326;MULTIPOLYGON(((2 0,2 1,3 1,3 0,2 0)))']);

        $population = CriterionFactory::createOne(['code' => 'population', 'valueType' => CriterionValueType::Integer]);
        $density = CriterionFactory::createOne(['code' => 'commerces_total_pour_1000_hab', 'valueType' => CriterionValueType::Decimal]);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);
        foreach ([$dense, $sparse, $unknown] as $uniteUrbaine) {
            $entityManager->persist(new CriterionValue($population, $uniteUrbaine, 10_000, 2023));
        }

        $entityManager->persist(new CriterionValue($density, $dense, 12.5, 2025));
        $entityManager->persist(new CriterionValue($density, $sparse, 4.2, 2025));
        $entityManager->flush();

        self::assertSame(['00001', '00002', '00003'], $this->codes($client, []));
        self::assertSame(['00001'], $this->codes($client, ['commerceDensityMin' => '10']));
        self::assertSame(['00001', '00002'], $this->codes($client, ['commerceDensityMin' => '4.2']));
    }

    public function testDataEndpointCanExcludeUnitesUrbainesNearANuclearPlant(): void
    {
        $client = self::createClient();

        // Centroids at 0.5°, 1.5° and 2.5° of longitude: ~11, ~100 and ~210 km from the plant.
        $near = UniteUrbaineFactory::createOne(['inseeCode' => '00001', 'boundary' => 'SRID=4326;MULTIPOLYGON(((0 0,0 1,1 1,1 0,0 0)))']);
        $middle = UniteUrbaineFactory::createOne(['inseeCode' => '00002', 'boundary' => 'SRID=4326;MULTIPOLYGON(((1 0,1 1,2 1,2 0,1 0)))']);
        $far = UniteUrbaineFactory::createOne(['inseeCode' => '00003', 'boundary' => 'SRID=4326;MULTIPOLYGON(((2 0,2 1,3 1,3 0,2 0)))']);

        $population = CriterionFactory::createOne(['code' => 'population', 'valueType' => CriterionValueType::Integer]);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);
        foreach ([$near, $middle, $far] as $uniteUrbaine) {
            $entityManager->persist(new CriterionValue($population, $uniteUrbaine, 10_000, 2023));
        }

        $entityManager->flush();

        PoiFactory::createOne([
            'category' => PoiCategoryFactory::createOne(['code' => 'nuclear_power_plant']),
            'location' => 'SRID=4326;POINT(0.6 0.5)',
        ]);
        // Another kind of POI never counts as a plant.
        PoiFactory::createOne(['location' => 'SRID=4326;POINT(2.5 0.5)']);

        self::assertSame(['00001', '00002', '00003'], $this->codes($client, []));
        self::assertSame(['00002', '00003'], $this->codes($client, ['nuclearExclusionKm' => '50']));
        self::assertSame(['00003'], $this->codes($client, ['nuclearExclusionKm' => '150']));
    }

    public function testNuclearPlantsEndpointReturnsThePlantsWithTheirDetails(): void
    {
        $client = self::createClient();

        PoiFactory::createOne([
            'name' => 'Le Blayais',
            'category' => PoiCategoryFactory::createOne(['code' => 'nuclear_power_plant']),
            'location' => 'SRID=4326;POINT(-0.690606 45.257605)',
            'attributes' => ['reactors' => 4, 'powerMw' => 3704],
        ]);
        PoiFactory::createOne(['name' => 'Not a plant']);

        $client->request('GET', '/carte/centrales');

        self::assertResponseIsSuccessful();
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        /** @var array{features: list<mixed>} $data */
        $data = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame([[
            'type' => 'Feature',
            'geometry' => ['type' => 'Point', 'coordinates' => [-0.690606, 45.257605]],
            'properties' => ['name' => 'Le Blayais', 'reactors' => 4, 'powerMw' => 3704],
        ]], $data['features']);
    }

    /**
     * @param array<string, string> $query
     *
     * @return list<string>
     */
    private function codes(KernelBrowser $client, array $query): array
    {
        $client->request('GET', '/carte/donnees', $query);

        self::assertResponseIsSuccessful();
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);

        /** @var array{features: list<array{properties: array{code: string}}>} $data */
        $data = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
        $codes = array_map(static fn (array $feature): string => $feature['properties']['code'], $data['features']);
        sort($codes);

        return $codes;
    }
}
