<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\CriterionValue;
use App\Entity\Poi;
use App\Entity\PoiCategory;
use App\Entity\StationAccess;
use App\Entity\UniteUrbaine;
use App\Enum\AttractionCategory;
use App\Enum\CriterionValueType;
use App\Factory\CriterionFactory;
use App\Factory\PoiCategoryFactory;
use App\Factory\PoiFactory;
use App\Factory\UniteUrbaineFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;
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

    public function testDataEndpointCanCapThePricePerSquareMeter(): void
    {
        $client = self::createClient();

        $cheap = UniteUrbaineFactory::createOne(['inseeCode' => '00001', 'boundary' => 'SRID=4326;MULTIPOLYGON(((0 0,0 1,1 1,1 0,0 0)))']);
        $expensive = UniteUrbaineFactory::createOne(['inseeCode' => '00002', 'boundary' => 'SRID=4326;MULTIPOLYGON(((1 0,1 1,2 1,2 0,1 0)))']);
        // No price (not covered by the source): cannot be said to stay under a cap.
        $unknown = UniteUrbaineFactory::createOne(['inseeCode' => '00003', 'boundary' => 'SRID=4326;MULTIPOLYGON(((2 0,2 1,3 1,3 0,2 0)))']);

        $population = CriterionFactory::createOne(['code' => 'population', 'valueType' => CriterionValueType::Integer]);
        $price = CriterionFactory::createOne(['code' => 'prix_m2', 'valueType' => CriterionValueType::Integer]);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);
        foreach ([$cheap, $expensive, $unknown] as $uniteUrbaine) {
            $entityManager->persist(new CriterionValue($population, $uniteUrbaine, 10_000, 2023));
        }

        // Only the latest year counts.
        $entityManager->persist(new CriterionValue($price, $cheap, 9_000, 2024));
        $entityManager->persist(new CriterionValue($price, $cheap, 2_500, 2025));
        $entityManager->persist(new CriterionValue($price, $expensive, 6_000, 2025));
        $entityManager->flush();

        self::assertSame(['00001', '00002', '00003'], $this->codes($client, []));
        self::assertSame(['00001', '00002'], $this->codes($client, ['priceMax' => '6000']));
        self::assertSame(['00001'], $this->codes($client, ['priceMax' => '2500']));
        self::assertSame([], $this->codes($client, ['priceMax' => '2499']));
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

    public function testDataEndpointCanKeepOnlyUnitesUrbainesWithAStation(): void
    {
        $client = self::createClient();

        $with = UniteUrbaineFactory::createOne(['inseeCode' => '00001', 'boundary' => 'SRID=4326;MULTIPOLYGON(((0 0,0 1,1 1,1 0,0 0)))']);
        $without = UniteUrbaineFactory::createOne(['inseeCode' => '00002', 'boundary' => 'SRID=4326;MULTIPOLYGON(((1 0,1 1,2 1,2 0,1 0)))']);
        $otherPoi = UniteUrbaineFactory::createOne(['inseeCode' => '00003', 'boundary' => 'SRID=4326;MULTIPOLYGON(((2 0,2 1,3 1,3 0,2 0)))']);

        $population = CriterionFactory::createOne(['code' => 'population', 'valueType' => CriterionValueType::Integer]);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);
        foreach ([$with, $without, $otherPoi] as $uniteUrbaine) {
            $entityManager->persist(new CriterionValue($population, $uniteUrbaine, 10_000, 2023));
        }

        $entityManager->flush();

        PoiFactory::createOne(['category' => PoiCategoryFactory::createOne(['code' => 'railway_station']), 'uniteUrbaine' => $with]);
        // Another kind of POI is not a station.
        PoiFactory::createOne(['uniteUrbaine' => $otherPoi]);

        self::assertSame(['00001', '00002', '00003'], $this->codes($client, []));
        self::assertSame(['00001'], $this->codes($client, ['withStation' => '1']));
    }

    public function testDataEndpointKeepsUnitesUrbainesWithinCarPlusTrainTime(): void
    {
        $client = self::createClient();
        [$near, $far, $viaBestStation, $unreachable, , $inside] = $this->createUnitesUrbaines(6);

        // Car minutes to the station, then train minutes to Paris (null: out of reach).
        $this->addAccess($near, 30, 'paris', 80);
        $this->addAccess($far, 30, 'paris', 100);
        // The closest station by car is slow by train: the second one wins.
        $this->addAccess($viaBestStation, 5, 'paris', 200);
        $this->addAccess($viaBestStation, 40, 'paris', 60, 2);
        $this->addAccess($unreachable, 5, 'paris', null);
        // The station of Paris itself has no journey to Paris: only the car counts.
        $this->addAccess($inside, 0, null, null);

        self::assertSame(['00001', '00002', '00003', '00004', '00005', '00006'], $this->codes($client, []));
        self::assertSame(['00001', '00003', '00006'], $this->codes($client, ['trainMaxMinutes' => ['paris' => '120']]));
        self::assertSame(['00001', '00002', '00003', '00006'], $this->codes($client, ['trainMaxMinutes' => ['paris' => '130']]));
        self::assertSame(['00006'], $this->codes($client, ['trainMaxMinutes' => ['paris' => '90']]));
        // Without any access station, no limit can be shown to hold.
        self::assertNotContains('00005', $this->codes($client, ['trainMaxMinutes' => ['paris' => '1000']]));
    }

    public function testDataEndpointCombinesSeveralTrainLimits(): void
    {
        $client = self::createClient();
        [$both, $parisOnly, $lyonOnly] = $this->createUnitesUrbaines(3);

        $this->addAccess($both, 10, 'paris', 60);
        $this->addJourney($this->lastStation, 'lyon', 60);
        // Every outside station has a row per destination, empty when out of reach.
        $this->addAccess($parisOnly, 10, 'paris', 60);
        $this->addJourney($this->lastStation, 'lyon', null);
        $this->addAccess($lyonOnly, 10, 'lyon', 60);
        $this->addJourney($this->lastStation, 'paris', null);

        self::assertSame(['00001', '00002'], $this->codes($client, ['trainMaxMinutes' => ['paris' => '90']]));
        self::assertSame(['00001'], $this->codes($client, ['trainMaxMinutes' => ['paris' => '90', 'lyon' => '90']]));
    }

    public function testDataEndpointRejectsAnUnknownTrainDestination(): void
    {
        $client = self::createClient();

        $client->request('GET', '/carte/donnees', ['trainMaxMinutes' => ['atlantis' => '90']]);
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/carte/donnees', ['trainMaxMinutes' => ['paris' => '-5']]);
        self::assertResponseStatusCodeSame(404);
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

    private ?Poi $lastStation = null;

    private ?PoiCategory $stationCategory = null;

    /**
     * @return list<UniteUrbaine>
     */
    private function createUnitesUrbaines(int $count): array
    {
        $population = CriterionFactory::createOne(['code' => 'population', 'valueType' => CriterionValueType::Integer]);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);

        $unitesUrbaines = [];
        for ($i = 0; $i < $count; ++$i) {
            $uniteUrbaine = UniteUrbaineFactory::createOne([
                'inseeCode' => \sprintf('%05d', $i + 1),
                'boundary' => \sprintf('SRID=4326;MULTIPOLYGON(((%1$d 0,%1$d 1,%2$d 1,%2$d 0,%1$d 0)))', $i, $i + 1),
            ]);
            $entityManager->persist(new CriterionValue($population, $uniteUrbaine, 10_000, 2023));
            $unitesUrbaines[] = $uniteUrbaine;
        }

        $entityManager->flush();

        return $unitesUrbaines;
    }

    /**
     * @param ?string $destination when set, the station has a journey there (null minutes: out of reach)
     */
    private function addAccess(UniteUrbaine $uniteUrbaine, int $carMinutes, ?string $destination, ?int $trainMinutes, int $position = 1): void
    {
        $this->stationCategory ??= PoiCategoryFactory::createOne(['code' => 'railway_station']);
        $station = PoiFactory::createOne(['category' => $this->stationCategory]);
        $this->lastStation = $station;

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);
        $entityManager->persist(new StationAccess($uniteUrbaine, $station, $position, $carMinutes * 1000, $carMinutes * 60, 2025));
        $entityManager->flush();

        if (null !== $destination) {
            $this->addJourney($station, $destination, $trainMinutes);
        }
    }

    private function addJourney(?Poi $station, string $destination, ?int $trainMinutes): void
    {
        \assert(null !== $station);
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        $connection->insert('station_journey', [
            'id' => Uuid::v7()->toRfc4122(),
            'station_id' => $station->getId()->toRfc4122(),
            'destination' => $destination,
            'reference_date' => '2026-10-13',
            'median_duration_minutes' => $trainMinutes,
            'created_at' => '2026-10-13 00:00:00',
            'updated_at' => '2026-10-13 00:00:00',
        ]);
    }

    /**
     * @param array<string, string|array<string, string>> $query
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
