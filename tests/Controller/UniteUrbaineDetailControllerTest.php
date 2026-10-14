<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CriterionValue;
use App\Entity\StationAccess;
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
use Symfony\Component\Uid\Uuid;
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
        self::assertSelectorTextContains('section#population > h2', 'Population');
        self::assertAnySelectorTextContains('section#population section h2', 'Démographie');
        self::assertSelectorExists('[data-label="Population"]');
        self::assertSelectorExists('[data-label="Population"] a[href="/criteres#population"]');
        self::assertSelectorExists('[data-label="Population"] a[href="/classements/population"]');
        self::assertSelectorTextContains('[data-label="Population"] [role="tooltip"]', "nombre d'habitants");
        self::assertSelectorTextContains('[data-label="Population"] [role="tooltip"]', 'Source : INSEE');
        self::assertSelectorNotExists('a[data-turbo-frame="_top"]');
        // A criterion with a series unfolds a chart, fed by the series endpoint.
        self::assertSelectorExists('[data-controller="criterion-chart"][data-criterion-chart-url-value="/unites-urbaines/56501/criteres/population/serie"]');
        self::assertSelectorExists('[data-criterion-chart-open-value="true"]');
    }

    public function testPageShowsTheClimateGroupWithItsAttribution(): void
    {
        $client = self::createClient();

        $marseille = UniteUrbaineFactory::createOne(['inseeCode' => '13701']);
        UniteUrbaineFactory::createOne(['inseeCode' => '29701']);
        $sunshine = CriterionFactory::createOne(['code' => 'ensoleillement', 'label' => 'Ensoleillement', 'unit' => 'h/an', 'valueType' => CriterionValueType::Integer, 'groupLabel' => 'Climat']);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);
        $entityManager->persist(new CriterionValue($sunshine, $marseille, 2_860, 2020));
        $entityManager->flush();

        $client->request('GET', '/unites-urbaines/13701');

        self::assertResponseIsSuccessful();
        self::assertAnySelectorTextContains('section h2', 'Climat');
        self::assertSelectorExists('[data-label="Ensoleillement"]');
        self::assertAnySelectorTextContains('section p', 'Données météo : Open-Meteo.com (CC BY 4.0)');
    }

    public function testPageDrawsTheAgePyramidWhenKnown(): void
    {
        $client = self::createClient();

        UniteUrbaineFactory::createOne(['inseeCode' => '56501', 'agePyramid' => ['year' => 2023, 'women' => [30, 20, 10], 'men' => [40, 10, 0]]]);
        UniteUrbaineFactory::createOne(['inseeCode' => '44701']);

        $client->request('GET', '/unites-urbaines/56501');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('section[data-controller="age-pyramid"] h2', 'Pyramide des âges');
        self::assertSelectorExists('svg [data-tooltip*="0-4 ans"][data-tooltip*="40 hommes"][data-tooltip*="30 femmes"]');
        self::assertSelectorExists('svg [data-tooltip*="10 et + ans"][data-tooltip*="10 femmes"]');
        // The last bracket is open-ended, and the shares of each sex are given.
        self::assertSelectorTextContains('svg[role="img"]', '10 et +');
        self::assertSelectorExists('svg[role="img"][aria-label*="110 habitants"]');
        self::assertSelectorTextContains('section[data-controller="age-pyramid"]', 'Hommes · 45,5 %');
        // The same figures as a table, youngest first, with the total.
        self::assertSelectorTextContains('table tbody tr:first-child', '0-4 ans');
        self::assertSelectorTextContains('table tbody tr:first-child', '70');
        self::assertSelectorTextContains('table tfoot', '110');

        // The full page offers both bracket widths, five years first.
        self::assertSelectorExists('[data-controller="age-pyramid"] button[aria-pressed="true"][data-age-pyramid-width-param="5"]');
        self::assertSelectorExists('[data-controller="age-pyramid"] button[aria-pressed="false"][data-age-pyramid-width-param="10"]');
        self::assertSelectorExists('[data-age-pyramid-target="panel"][data-width="5"]:not([hidden])');
        self::assertSelectorExists('[data-age-pyramid-target="panel"][data-width="10"][hidden]');
        self::assertSelectorExists('svg [data-tooltip*="0-9 ans"][data-tooltip*="50 hommes"]');

        $client->request('GET', '/unites-urbaines/44701');
        self::assertSelectorNotExists('[role="img"]');
    }

    public function testMapPanelDrawsTheAgePyramidInTenYearBracketsOnly(): void
    {
        $client = self::createClient();

        UniteUrbaineFactory::createOne(['inseeCode' => '56501', 'agePyramid' => ['year' => 2023, 'women' => [30, 20, 10], 'men' => [40, 10, 0]]]);

        $client->request('GET', '/unites-urbaines/56501', server: ['HTTP_TURBO_FRAME' => 'unite-urbaine-detail']);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('svg [data-tooltip*="0-9 ans"][data-tooltip*="50 hommes"]');
        self::assertSelectorExists('svg [data-tooltip*="10 et + ans"][data-tooltip*="10 femmes"]');
        self::assertSelectorNotExists('svg [data-tooltip*="0-4 ans"]');
        self::assertSelectorNotExists('[data-age-pyramid-width-param]');
    }

    public function testIncomeBracketsAreDrawnAsOneChartAgainstTheMedian(): void
    {
        $client = self::createClient();

        $vannes = UniteUrbaineFactory::createOne(['inseeCode' => '56501']);
        $other = UniteUrbaineFactory::createOne(['inseeCode' => '44701']);
        $mean = CriterionFactory::createOne(['code' => 'revenu_fiscal_moyen', 'label' => 'Revenu fiscal moyen par foyer', 'unit' => '€', 'valueType' => CriterionValueType::Decimal, 'groupLabel' => 'Revenus fiscaux', 'position' => 0]);
        $small = CriterionFactory::createOne(['code' => 'part_foyers_moins_15000', 'label' => 'Foyers à moins de 15 000 €', 'unit' => '%', 'valueType' => CriterionValueType::Decimal, 'groupLabel' => 'Revenus fiscaux', 'position' => 1]);
        $big = CriterionFactory::createOne(['code' => 'part_foyers_plus_100000', 'label' => 'Foyers à plus de 100 000 €', 'unit' => '%', 'valueType' => CriterionValueType::Decimal, 'groupLabel' => 'Revenus fiscaux', 'position' => 2]);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);
        $entityManager->persist(new CriterionValue($mean, $vannes, '32000', 2024));
        $entityManager->persist(new CriterionValue($small, $vannes, '24', 2024));
        $entityManager->persist(new CriterionValue($small, $other, '30', 2024));
        $entityManager->persist(new CriterionValue($big, $vannes, '3', 2024));
        $entityManager->persist(new CriterionValue($big, $other, '1', 2024));
        $entityManager->flush();

        $client->request('GET', '/unites-urbaines/56501');

        self::assertResponseIsSuccessful();
        // The mean keeps its row; the brackets are one chart, not one row each.
        self::assertSelectorExists('[data-label="Revenu fiscal moyen par foyer"]');
        self::assertSelectorNotExists('[data-label="Foyers à moins de 15 000 €"]');
        self::assertSelectorExists('svg[role="img"][aria-label^="Foyers fiscaux par tranche de revenu fiscal"]');
        self::assertSelectorExists('svg [data-tooltip*="Foyers à moins de 15 000 €"][data-tooltip*="Cette unité urbaine : 24 % (médiane : 27 %)"]');
        // The tooltip is ours, instant, not the browser's delayed one.
        self::assertSelectorExists('[data-controller="chart-tooltip"] [data-chart-tooltip-target="tooltip"][hidden]');
        self::assertSelectorNotExists('svg title');
        self::assertAnySelectorTextContains('svg text', '< 15 k€');
        self::assertAnySelectorTextContains('svg text', '> 100 k€');
        // The chart is found by the label of its brackets.
        self::assertSelectorExists('[data-label*="100 000"]');
    }

    public function testCriterionRankedFromTheLowestPutsTheLowestValueFirst(): void
    {
        $client = self::createClient();

        $low = UniteUrbaineFactory::createOne(['inseeCode' => '56501']);
        $high = UniteUrbaineFactory::createOne(['inseeCode' => '44701']);
        $poverty = CriterionFactory::createOne(['code' => 'taux_pauvrete', 'label' => 'Taux de pauvreté', 'unit' => '%', 'valueType' => CriterionValueType::Decimal, 'groupLabel' => 'Revenus', 'rankFromLowest' => true]);
        $income = CriterionFactory::createOne(['code' => 'niveau_vie_median', 'label' => 'Niveau de vie médian', 'unit' => '€/an', 'valueType' => CriterionValueType::Integer, 'groupLabel' => 'Revenus']);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);
        $entityManager->persist(new CriterionValue($poverty, $low, '8.5', 2023));
        $entityManager->persist(new CriterionValue($poverty, $high, '21.7', 2023));
        $entityManager->persist(new CriterionValue($income, $low, 30_000, 2023));
        $entityManager->persist(new CriterionValue($income, $high, 20_000, 2023));
        $entityManager->flush();

        // The lowest poverty rate is first, while the highest income is first.
        $client->request('GET', '/unites-urbaines/56501');
        self::assertSelectorTextContains('[data-label="Taux de pauvreté"]', '1er sur 2');
        self::assertSelectorTextContains('[data-label="Niveau de vie médian"]', '1er sur 2');

        $client->request('GET', '/unites-urbaines/44701');
        self::assertSelectorTextContains('[data-label="Taux de pauvreté"]', '2e sur 2');
        self::assertSelectorTextContains('[data-label="Niveau de vie médian"]', '2e sur 2');
    }

    public function testTrendIsColoredByTheRankOrder(): void
    {
        $client = self::createClient();

        $unite = UniteUrbaineFactory::createOne(['inseeCode' => '56501']);
        $other = UniteUrbaineFactory::createOne(['inseeCode' => '44701']);
        $price = CriterionFactory::createOne(['code' => 'prix_m2', 'label' => 'Prix au m²', 'unit' => '€/m²', 'valueType' => CriterionValueType::Integer, 'groupLabel' => 'Immobilier']);
        $unemployment = CriterionFactory::createOne(['code' => 'taux_chomage', 'label' => 'Taux de chômage', 'unit' => '%', 'valueType' => CriterionValueType::Decimal, 'groupLabel' => 'Emploi', 'rankFromLowest' => true]);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);
        foreach ([$unite, $other] as $uniteUrbaine) {
            $entityManager->persist(new CriterionValue($price, $uniteUrbaine, 2_000, 2021));
            $entityManager->persist(new CriterionValue($price, $uniteUrbaine, 2_500, 2025));
            $entityManager->persist(new CriterionValue($unemployment, $uniteUrbaine, '9.0', 2012));
            $entityManager->persist(new CriterionValue($unemployment, $uniteUrbaine, '11.5', 2022));
        }

        $entityManager->flush();

        $client->request('GET', '/unites-urbaines/56501');

        self::assertResponseIsSuccessful();
        // Higher is better for the price (rank order): a rise is favourable.
        self::assertSelectorTextContains('[data-label="Prix au m²"] .group\\/trend.text-emerald-700', '+25 % depuis 2021');
        // The period is in our own instant tooltip, not the browser's delayed title.
        self::assertSelectorTextContains('[data-label="Prix au m²"] .group\\/trend [aria-hidden].absolute', 'Évolution entre 2021 et 2025');
        // Lower is better for unemployment: a rise is unfavourable, in points.
        self::assertSelectorTextContains('[data-label="Taux de chômage"] .group\\/trend.text-rose-700', '+2,5 pt depuis 2012');
    }

    public function testClimateProjectionsAreListedUnderTheirOwnGroup(): void
    {
        $client = self::createClient();

        $marseille = UniteUrbaineFactory::createOne(['inseeCode' => '13701']);
        $brest = UniteUrbaineFactory::createOne(['inseeCode' => '29201']);
        $hotDays = CriterionFactory::createOne(['code' => 'jours_plus_35_2050', 'label' => 'Jours > 35 °C vers 2050', 'unit' => 'j/an', 'valueType' => CriterionValueType::Decimal, 'groupLabel' => 'Climat 2050', 'rankFromLowest' => true]);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);
        $entityManager->persist(new CriterionValue($hotDays, $marseille, '18.3', 2050));
        $entityManager->persist(new CriterionValue($hotDays, $brest, '0.4', 2050));
        $entityManager->flush();

        $client->request('GET', '/unites-urbaines/13701');

        self::assertResponseIsSuccessful();
        self::assertAnySelectorTextContains('section h2', 'Climat 2050');
        self::assertSelectorTextContains('[data-label="Jours > 35 °C vers 2050"]', '2e sur 2');
    }

    public function testSocioProfessionalCategoriesAreDrawnAsRows(): void
    {
        $client = self::createClient();

        $vannes = UniteUrbaineFactory::createOne(['inseeCode' => '56501']);
        $other = UniteUrbaineFactory::createOne(['inseeCode' => '44701']);
        $cadres = CriterionFactory::createOne(['code' => 'part_cadres', 'label' => 'Cadres et professions intellectuelles supérieures', 'unit' => '%', 'valueType' => CriterionValueType::Decimal, 'groupLabel' => 'Catégories socioprofessionnelles', 'position' => 2]);
        $retraites = CriterionFactory::createOne(['code' => 'part_retraites', 'label' => 'Retraités', 'unit' => '%', 'valueType' => CriterionValueType::Decimal, 'groupLabel' => 'Catégories socioprofessionnelles', 'position' => 6]);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);
        $entityManager->persist(new CriterionValue($cadres, $vannes, '12.5', 2023));
        $entityManager->persist(new CriterionValue($cadres, $other, '8', 2023));
        $entityManager->persist(new CriterionValue($retraites, $vannes, '31', 2023));
        $entityManager->persist(new CriterionValue($retraites, $other, '25', 2023));
        $entityManager->flush();

        $client->request('GET', '/unites-urbaines/56501');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-label="Retraités"]');
        self::assertSelectorExists('svg[role="img"][aria-label^="Population de 15 ans ou plus par catégorie socioprofessionnelle"]');
        self::assertSelectorExists('svg [data-tooltip*="Retraités"][data-tooltip*="Cette unité urbaine : 31 % (médiane : 28 %)"]');
        // A row per category, labelled on the left of its bar.
        self::assertAnySelectorTextContains('svg text', 'Cadres');
        self::assertAnySelectorTextContains('svg text', 'Retraités');
        self::assertSelectorExists('svg rect[x="146"]');
    }

    public function testPageListsTheClosestStationsWithTheirCarRoute(): void
    {
        $client = self::createClient();

        $vannes = UniteUrbaineFactory::createOne(['inseeCode' => '56501']);
        $category = PoiCategoryFactory::createOne(['code' => 'railway_station']);
        $auray = PoiFactory::createOne(['category' => $category, 'name' => 'Auray']);
        $questembert = PoiFactory::createOne(['category' => $category, 'name' => 'Questembert']);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);
        $entityManager->persist(new StationAccess($vannes, $auray, 1, 12_300, 1_500, 2026));
        $entityManager->persist(new StationAccess($vannes, $questembert, 2, 20_000, 1_800, 2026));
        $entityManager->flush();

        $client->request('GET', '/unites-urbaines/56501');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('turbo-frame#unite-urbaine-detail', 'Gares les plus proches');
        self::assertSelectorTextContains('turbo-frame#unite-urbaine-detail', 'Auray');
        self::assertSelectorTextContains('turbo-frame#unite-urbaine-detail', "~25\u{a0}min · 12,3\u{a0}km");
        self::assertSelectorTextContains('turbo-frame#unite-urbaine-detail', 'Questembert');
        // Stations have their own block: they are not repeated among the points of interest.
        self::assertSelectorTextNotContains('turbo-frame#unite-urbaine-detail', "Points d'intérêt");
    }

    public function testStationsShowTheirJourneysToTheMajorCities(): void
    {
        $client = self::createClient();

        $vannes = UniteUrbaineFactory::createOne(['inseeCode' => '56501']);
        $category = PoiCategoryFactory::createOne(['code' => 'railway_station']);
        $auray = PoiFactory::createOne(['category' => $category, 'name' => 'Auray']);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);
        $entityManager->persist(new StationAccess($vannes, $auray, 1, 12_300, 1_500, 2026));
        $entityManager->flush();

        $connection = $entityManager->getConnection();
        foreach ([['nantes', 0, 7, 77], ['paris', null, null, null], ['lyon', 1, 4, 135], ['marseille', 0, 3, 190], ['bordeaux', 0, 5, 95], ['toulouse', 1, 6, 150], ['lille', 0, 12, 45], ['rennes', 1, 3, 100], ['strasbourg', 0, 8, 80], ['montpellier', 2, 5, 125], ['nice', 1, 4, 330]] as [$destination, $transfers, $trains, $minutes]) {
            $connection->insert('station_journey', [
                'id' => Uuid::v7()->toRfc4122(),
                'station_id' => $auray->getId()->toRfc4122(),
                'destination' => $destination,
                'reference_date' => '2026-10-13',
                'transfers' => $transfers,
                'trains_per_day' => $trains,
                'median_duration_minutes' => $minutes,
                'created_at' => '2026-10-10 10:00:00',
                'updated_at' => '2026-10-10 10:00:00',
            ]);
        }

        $client->request('GET', '/unites-urbaines/56501');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('turbo-frame#unite-urbaine-detail', "1\u{a0}h\u{a0}17 · 7\u{a0}trains/j · direct");
        self::assertSelectorTextContains('turbo-frame#unite-urbaine-detail', 'pas de liaison');
        self::assertSelectorTextContains('turbo-frame#unite-urbaine-detail', "2\u{a0}h\u{a0}15 · 4\u{a0}trains/j · 1 correspondance");
        self::assertSelectorTextContains('turbo-frame#unite-urbaine-detail', "3\u{a0}h\u{a0}10 · 3\u{a0}trains/j · direct");
        self::assertSelectorTextContains('turbo-frame#unite-urbaine-detail', "1\u{a0}h\u{a0}35 · 5\u{a0}trains/j · direct");
        self::assertSelectorTextContains('turbo-frame#unite-urbaine-detail', "2\u{a0}h\u{a0}30 · 6\u{a0}trains/j · 1 correspondance");
        self::assertSelectorTextContains('turbo-frame#unite-urbaine-detail', "45\u{a0}min · 12\u{a0}trains/j · direct");
        self::assertSelectorTextContains('turbo-frame#unite-urbaine-detail', "1\u{a0}h\u{a0}40 · 3\u{a0}trains/j · 1 correspondance");
        self::assertSelectorTextContains('turbo-frame#unite-urbaine-detail', "1\u{a0}h\u{a0}20 · 8\u{a0}trains/j · direct");
        self::assertSelectorTextContains('turbo-frame#unite-urbaine-detail', "2\u{a0}h\u{a0}05 · 5\u{a0}trains/j · 2 correspondances");
        self::assertSelectorTextContains('turbo-frame#unite-urbaine-detail', "5\u{a0}h\u{a0}30 · 4\u{a0}trains/j · 1 correspondance");
        self::assertSelectorTextContains('turbo-frame#unite-urbaine-detail', '13/10/2026');

        $labels = $client->getCrawler()->filter('turbo-frame#unite-urbaine-detail dt')->each(static fn ($node): string => $node->text());
        self::assertSelectorTextContains('turbo-frame#unite-urbaine-detail details summary', 'Voir les 7 autres destinations');
        self::assertCount(4, $client->getCrawler()->filter('turbo-frame#unite-urbaine-detail section li > dl > dt'));
        self::assertSame(['Lille', 'Nantes', 'Strasbourg', 'Bordeaux', 'Rennes', 'Montpellier', 'Lyon', 'Toulouse', 'Marseille', 'Nice', 'Paris'], $labels);
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
        self::assertSelectorExists('[data-label="80 ans ou plus"]');

        $vannes->setAgePyramid(['year' => 2023, 'women' => [30, 20, 10], 'men' => [40, 10, 0]]);
        $entityManager->flush();

        // With a pyramid, the shares join its table instead of a card of their own.
        $client->request('GET', '/unites-urbaines/56501');
        self::assertSelectorExists('section[data-controller="age-pyramid"] tbody[data-label="80 ans ou plus"]');

        $client->request('GET', '/unites-urbaines/56501', server: ['HTTP_TURBO_FRAME' => 'unite-urbaine-detail']);
        self::assertSelectorNotExists('[data-label="80 ans ou plus"]');
    }

    public function testPageShowsTheRisksGroup(): void
    {
        $client = self::createClient();

        $nice = UniteUrbaineFactory::createOne(['inseeCode' => '06701']);
        $flood = CriterionFactory::createOne(['code' => 'part_zone_inondable', 'label' => 'Population en zone inondable', 'unit' => '%', 'valueType' => CriterionValueType::Decimal, 'groupLabel' => 'Risques', 'rankFromLowest' => true]);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);
        $entityManager->persist(new CriterionValue($flood, $nice, '75', 2026));
        $entityManager->flush();

        $client->request('GET', '/unites-urbaines/06701');

        self::assertResponseIsSuccessful();
        self::assertAnySelectorTextContains('section h2', 'Risques');
        self::assertSelectorExists('[data-label="Population en zone inondable"]');
    }

    public function testUnknownUniteUrbaineReturns404(): void
    {
        $client = self::createClient();

        $client->request('GET', '/unites-urbaines/99999');

        self::assertResponseStatusCodeSame(404);
    }
}
