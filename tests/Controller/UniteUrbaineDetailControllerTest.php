<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CriterionValue;
use App\Enum\AttractionCategory;
use App\Enum\CriterionValueType;
use App\Enum\Departement;
use App\Factory\CommuneFactory;
use App\Factory\CriterionFactory;
use App\Factory\PoiCategoryFactory;
use App\Factory\PoiFactory;
use App\Factory\UniteUrbaineFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class UniteUrbaineDetailControllerTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    public function testPageShowsTheUniteUrbaineWithItsCriteria(): void
    {
        $client = self::createClient();

        $vannes = UniteUrbaineFactory::createOne(['inseeCode' => '56501', 'name' => 'Vannes', 'departement' => Departement::Morbihan]);
        $other = UniteUrbaineFactory::createOne(['inseeCode' => '44701']);
        $population = CriterionFactory::createOne(['code' => 'population', 'label' => 'Population', 'unit' => 'hab.', 'valueType' => CriterionValueType::Integer, 'groupLabel' => 'Démographie']);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);
        $entityManager->persist(new CriterionValue($population, $vannes, 84_176, 2023));
        $entityManager->persist(new CriterionValue($population, $vannes, 80_000, 2022));
        $entityManager->persist(new CriterionValue($population, $other, 500_000, 2023));
        $entityManager->flush();

        $client->request('GET', '/unites-urbaines/56501');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Unité urbaine de Vannes');
        self::assertSelectorTextContains('header', "84\u{a0}176");
        self::assertSelectorTextContains('header', '2e sur 2');
        self::assertSelectorTextContains('details summary', 'Démographie');
        self::assertSelectorExists('turbo-frame#unite-urbaine-detail polyline');
        self::assertSelectorExists('[data-controller="criteria-filter"] input[type="search"]');
        self::assertSelectorExists('[data-criteria-filter-target="row"][data-label="Population"]');
        self::assertSelectorNotExists('a[data-turbo-frame="_top"]');
    }

    public function testPageListsPoisByCategoryAndCommunes(): void
    {
        $client = self::createClient();

        $vannes = UniteUrbaineFactory::createOne(['inseeCode' => '56501']);
        CommuneFactory::createOne(['name' => 'Séné', 'uniteUrbaine' => $vannes]);
        CommuneFactory::createOne(['name' => 'Plescop', 'uniteUrbaine' => $vannes]);
        $category = PoiCategoryFactory::createOne(['code' => 'hardware_store', 'label' => 'Grande surface de bricolage']);
        PoiFactory::createMany(12, ['category' => $category, 'uniteUrbaine' => $vannes]);
        PoiFactory::createOne(['name' => 'Leroy Merlin Vannes', 'brand' => 'Leroy Merlin', 'category' => $category, 'uniteUrbaine' => $vannes]);

        $client->request('GET', '/unites-urbaines/56501');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Grande surface de bricolage (13)');
        self::assertSelectorTextContains('body', 'et 3 autres');
        self::assertSelectorTextContains('body', 'Communes (2)');
        self::assertSelectorTextContains('body', 'Plescop');
    }

    public function testPageSaysWhichAireDAttractionTheUniteUrbaineDependsOn(): void
    {
        $client = self::createClient();
        $arradon = UniteUrbaineFactory::createOne(['inseeCode' => '56202', 'name' => 'Arradon']);
        $arradon->setAttraction(AttractionCategory::Couronne, '057', 'Vannes');

        $client->request('GET', '/unites-urbaines/56202');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('header', "Couronne · aire d'attraction de Vannes");
    }

    public function testFrameRequestOffersTheFullPage(): void
    {
        $client = self::createClient();
        UniteUrbaineFactory::createOne(['inseeCode' => '56501']);

        $client->request('GET', '/unites-urbaines/56501', server: ['HTTP_TURBO_FRAME' => 'unite-urbaine-detail']);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[data-turbo-frame="_top"][href="/unites-urbaines/56501"]');
    }

    public function testUnknownUniteUrbaineReturns404(): void
    {
        $client = self::createClient();

        $client->request('GET', '/unites-urbaines/99999');

        self::assertResponseStatusCodeSame(404);
    }
}
