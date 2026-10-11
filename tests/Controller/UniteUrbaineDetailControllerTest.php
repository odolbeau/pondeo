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
        self::assertSelectorExists('[data-controller="criteria-filter"] input[type="search"]');
        self::assertSelectorExists('[data-criteria-filter-target="row"][data-label="Population"]');
        self::assertSelectorNotExists('a[data-turbo-frame="_top"]');
        // A criterion with a series unfolds a chart, fed by the series endpoint.
        self::assertSelectorExists('[data-controller="criterion-chart"][data-criterion-chart-url-value="/unites-urbaines/56501/criteres/population/serie"]');
    }

    public function testPageDrawsTheAgePyramidWhenKnown(): void
    {
        $client = self::createClient();

        UniteUrbaineFactory::createOne(['inseeCode' => '56501', 'agePyramid' => ['year' => 2023, 'women' => [30, 20, 10], 'men' => [40, 10, 0]]]);
        UniteUrbaineFactory::createOne(['inseeCode' => '44701']);

        $client->request('GET', '/unites-urbaines/56501');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('details summary', 'Pyramide des âges');
        self::assertAnySelectorTextContains('svg[role="img"] title', '0-4 ans : 40 hommes');
        self::assertAnySelectorTextContains('svg[role="img"] title', '10 et + ans : 10 femmes');
        // The last bracket is open-ended, and the shares of each sex are given.
        self::assertSelectorTextContains('svg[role="img"]', '10 et +');
        self::assertSelectorExists('svg[role="img"][aria-label*="110 habitants"]');
        self::assertSelectorTextContains('details', 'Hommes · 45,5 %');
        // The same figures as a table, youngest first, with the total.
        self::assertSelectorTextContains('table tbody tr:first-child', '0-4 ans');
        self::assertSelectorTextContains('table tbody tr:first-child', '70');
        self::assertSelectorTextContains('table tfoot', '110');

        // The full page offers both bracket widths, five years first.
        self::assertSelectorExists('[data-controller="age-pyramid"] button[aria-pressed="true"][data-age-pyramid-width-param="5"]');
        self::assertSelectorExists('[data-controller="age-pyramid"] button[aria-pressed="false"][data-age-pyramid-width-param="10"]');
        self::assertSelectorExists('[data-age-pyramid-target="panel"][data-width="5"]:not([hidden])');
        self::assertSelectorExists('[data-age-pyramid-target="panel"][data-width="10"][hidden]');
        self::assertAnySelectorTextContains('svg[role="img"] title', '0-9 ans : 50 hommes');

        $client->request('GET', '/unites-urbaines/44701');
        self::assertSelectorNotExists('[role="img"]');
    }

    public function testMapPanelDrawsTheAgePyramidInTenYearBracketsOnly(): void
    {
        $client = self::createClient();

        UniteUrbaineFactory::createOne(['inseeCode' => '56501', 'agePyramid' => ['year' => 2023, 'women' => [30, 20, 10], 'men' => [40, 10, 0]]]);

        $client->request('GET', '/unites-urbaines/56501', server: ['HTTP_TURBO_FRAME' => 'unite-urbaine-detail']);

        self::assertResponseIsSuccessful();
        self::assertAnySelectorTextContains('svg[role="img"] title', '0-9 ans : 50 hommes');
        self::assertAnySelectorTextContains('svg[role="img"] title', '10 et + ans : 10 femmes');
        self::assertSelectorNotExists('svg[role="img"] title:contains("0-4 ans")');
        self::assertSelectorNotExists('[data-age-pyramid-width-param]');
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

    public function testAgeSharesAreLeftOutOfTheMapPanelOnly(): void
    {
        $client = self::createClient();
        $vannes = UniteUrbaineFactory::createOne(['inseeCode' => '56501']);
        $criterion = CriterionFactory::createOne(['code' => 'part_80_ans_et_plus', 'label' => '80 ans ou plus', 'unit' => '%', 'valueType' => CriterionValueType::Decimal, 'groupLabel' => 'Âge']);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);
        $entityManager->persist(new CriterionValue($criterion, $vannes, '8.16', 2023));
        $entityManager->flush();

        $client->request('GET', '/unites-urbaines/56501');
        self::assertSelectorExists('[data-criteria-filter-target="row"][data-label="80 ans ou plus"]');

        $client->request('GET', '/unites-urbaines/56501', server: ['HTTP_TURBO_FRAME' => 'unite-urbaine-detail']);
        self::assertSelectorNotExists('[data-criteria-filter-target="row"][data-label="80 ans ou plus"]');
    }

    public function testUnknownUniteUrbaineReturns404(): void
    {
        $client = self::createClient();

        $client->request('GET', '/unites-urbaines/99999');

        self::assertResponseStatusCodeSame(404);
    }
}
