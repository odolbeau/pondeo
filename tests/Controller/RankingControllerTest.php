<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Criterion;
use App\Entity\CriterionValue;
use App\Entity\UniteUrbaine;
use App\Enum\CriterionValueType;
use App\Factory\CriterionFactory;
use App\Factory\UniteUrbaineFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class RankingControllerTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    public function testRankingIsOrderedFromTheBestEnd(): void
    {
        $client = self::createClient();
        $this->createValues('population', ['Petite' => 100, 'Grande' => 300, 'Moyenne' => 200]);

        $client->request('GET', '/classements/population');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Les plus peuplées');
        self::assertSelectorTextContains('main section', 'Source : INSEE');
        self::assertSelectorExists('main section a[href="/criteres#population"]');
        self::assertSelectorTextContains('tbody tr:first-child', 'Grande');
        self::assertSelectorTextContains('tbody tr:last-child', 'Petite');
    }

    public function testPageShowsTheMedianOfAllUnits(): void
    {
        $client = self::createClient();
        $this->createValues('population', ['Petite' => 100, 'Moyenne' => 200, 'Grande' => 900]);

        $client->request('GET', '/classements/population');

        self::assertSelectorTextContains('main', 'médiane : 200');
        self::assertSelectorCount(3, 'tbody [title="Médiane"]');
    }

    public function testRowsShowTheirTrendSinceTheFirstPeriod(): void
    {
        $client = self::createClient();
        $criterion = $this->createValues('taux_chomage', ['Haut' => 12.5, 'Bas' => 6.1], rankFromLowest: true);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);
        $haut = $entityManager->getRepository(UniteUrbaine::class)->findOneBy(['name' => 'Haut']);
        \assert($haut instanceof UniteUrbaine);
        $entityManager->persist(new CriterionValue($criterion, $haut, 10.0, 2018));
        $entityManager->flush();

        $client->request('GET', '/classements/taux_chomage?ordre=inverse');

        self::assertResponseIsSuccessful();
        // Lower is better for unemployment: a rise is unfavourable.
        self::assertSelectorTextContains('tbody tr:first-child .group\\/trend.text-rose-700', '+25 % depuis 2018');
        // A unit with a single period has no trend.
        self::assertSelectorNotExists('tbody tr:last-child .group\\/trend');
    }

    public function testCriterionRankedFromLowestStartsWithTheLowestValue(): void
    {
        $client = self::createClient();
        $this->createValues('taux_chomage', ['Haut' => 12.5, 'Bas' => 6.1], rankFromLowest: true);

        $client->request('GET', '/classements/taux_chomage');

        self::assertSelectorTextContains('tbody tr:first-child', 'Bas');

        $client->request('GET', '/classements/taux_chomage?ordre=inverse');

        self::assertSelectorTextContains('tbody tr:first-child', 'Haut');
    }

    public function testRowsAreComparedAtTheLatestPeriodOverall(): void
    {
        $client = self::createClient();
        $criterion = $this->createValues('population', ['Ancienne' => 900, 'Recente' => 100]);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);
        $recente = $entityManager->getRepository(UniteUrbaine::class)->findOneBy(['name' => 'Recente']);
        \assert($recente instanceof UniteUrbaine);
        $entityManager->persist(new CriterionValue($criterion, $recente, 150, 2024));
        $entityManager->flush();

        $client->request('GET', '/classements/population');

        self::assertSelectorTextContains('tbody', 'Recente');
        self::assertSelectorTextNotContains('tbody', 'Ancienne');
    }

    public function testProjectionRankingStartsWithTheFewestHotDays(): void
    {
        $client = self::createClient();
        $this->createValues('jours_plus_35_2050', ['Brest' => 0.1, 'Marseille' => 9.1], rankFromLowest: true);

        $client->request('GET', '/classements/jours_plus_35_2050');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Le moins de canicules en 2050');
        self::assertSelectorTextContains('tbody tr:first-child', 'Brest');
    }

    public function testRentRankingStartsWithTheHighestRent(): void
    {
        $client = self::createClient();
        $this->createValues('loyer_m2_appartement', ['Guéret' => 8.4, 'Paris' => 24.7]);

        $client->request('GET', '/classements/loyer_m2_appartement');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Le loyer au m² des appartements');
        self::assertSelectorTextContains('main section', 'Source : Estimations ANIL');
        self::assertSelectorTextContains('tbody tr:first-child', 'Paris');
    }

    public function testFilterRanksTheSelectionAmongItselfAndKeepsTheNationalMedian(): void
    {
        $client = self::createClient();
        $this->createValues('loyer_m2_appartement', ['Petite' => 8.0, 'Moyenne' => 12.0, 'Grande' => 25.0, 'Autre' => 16.0]);
        $this->createPopulations(['Petite' => 5_000, 'Moyenne' => 50_000, 'Grande' => 500_000, 'Autre' => 60_000]);

        $client->request('GET', '/classements/loyer_m2_appartement?populationMin=10000&populationMax=100000');

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(2, 'tbody tr');
        self::assertSelectorTextContains('tbody tr:first-child', 'Autre');
        self::assertSelectorTextContains('tbody tr:first-child td:first-child', '1');
        self::assertSelectorTextContains('tbody tr:last-child td:first-child', '2');
        self::assertSelectorTextContains('tbody tr:last-child', 'Moyenne');
        // The median is that of the four units (14), not of the two kept.
        self::assertSelectorTextContains('main', '2 unités urbaines sur 4');
        self::assertSelectorTextContains('main', 'médiane nationale : 14');
    }

    public function testFilterKeepsTheOtherEndOfTheRankingAndIsKeptInTheLinks(): void
    {
        $client = self::createClient();
        $this->createValues('loyer_m2_appartement', ['Petite' => 8.0, 'Moyenne' => 12.0, 'Grande' => 25.0]);
        $this->createPopulations(['Petite' => 5_000, 'Moyenne' => 50_000, 'Grande' => 500_000]);

        $crawler = $client->request('GET', '/classements/loyer_m2_appartement?populationMin=10000&ordre=inverse');

        self::assertSelectorTextContains('tbody tr:first-child', 'Moyenne');
        self::assertSelectorCount(2, 'tbody tr');
        self::assertCount(1, $crawler->filter('a[href*="populationMin=10000"][href*="ordre=defaut"]'));
    }

    public function testPageOffersTheFiltersAndRefreshesItsResultsInPlace(): void
    {
        $client = self::createClient();
        $this->createValues('loyer_m2_appartement', ['Petite' => 8.0]);
        $this->createPopulations(['Petite' => 5_000]);

        $client->request('GET', '/classements/loyer_m2_appartement?ordre=inverse');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[data-turbo-frame="ranking-results"] [data-controller="filters"][data-filters-submit-value="true"][data-filters-population-max-value="5000"]');
        self::assertSelectorExists('[data-filters-keep-value=\'["ordre"]\']');
        self::assertSelectorExists('turbo-frame#ranking-results[data-turbo-action="advance"] tbody a[data-turbo-frame="_top"]');
    }

    public function testFilterWithoutAnyMatchSaysSo(): void
    {
        $client = self::createClient();
        $this->createValues('loyer_m2_appartement', ['Petite' => 8.0]);
        $this->createPopulations(['Petite' => 5_000]);

        $client->request('GET', '/classements/loyer_m2_appartement?populationMin=1000000');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('tbody');
        self::assertSelectorTextContains('main', 'Aucune unité urbaine ne correspond à ces filtres.');
    }

    public function testInvalidFilterIsRejected(): void
    {
        $client = self::createClient();
        $this->createValues('population', ['Vannes' => 100]);

        $client->request('GET', '/classements/population?populationMin=-5');

        self::assertResponseStatusCodeSame(404);
    }

    public function testIndexGroupsClimateAndRiskRankingsByTheme(): void
    {
        $client = self::createClient();
        $this->createValues('ensoleillement', ['Marseille' => 3803, 'Brest' => 3217]);

        $client->request('GET', '/classements');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Climat');
        self::assertSelectorTextContains('main', 'Les plus ensoleillées');
    }

    public function testRowLinksToTheUniteUrbaineDetail(): void
    {
        $client = self::createClient();
        $this->createValues('population', ['Vannes' => 100]);

        $client->request('GET', '/classements/population');

        self::assertSelectorExists('tbody a[href="/unites-urbaines/00001"]');
    }

    public function testUnknownRankingIsNotFound(): void
    {
        $client = self::createClient();
        CriterionFactory::createOne(['code' => 'part_ouvriers', 'valueType' => CriterionValueType::Decimal]);

        $client->request('GET', '/classements/part_ouvriers');

        self::assertResponseStatusCodeSame(404);
    }

    public function testIndexListsTopOfEachRanking(): void
    {
        $client = self::createClient();
        $this->createValues('population', ['Vannes' => 100]);

        $client->request('GET', '/classements');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Les plus peuplées');
        self::assertSelectorTextContains('main', 'Vannes');
    }

    /**
     * The population of units already created by {@see self::createValues()}, which the filters bound.
     *
     * @param array<string, int> $populations by unité urbaine name
     */
    private function createPopulations(array $populations): void
    {
        $criterion = CriterionFactory::createOne(['code' => 'population', 'label' => 'Population', 'valueType' => CriterionValueType::Integer]);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);
        foreach ($populations as $name => $population) {
            $entityManager->persist(new CriterionValue($criterion, UniteUrbaineFactory::new()->findOrCreate(['name' => (string) $name]), $population, 2023));
        }

        $entityManager->flush();
    }

    /**
     * @param array<string, int|float> $values unité urbaine name => value (2023)
     */
    private function createValues(string $code, array $values, bool $rankFromLowest = false): Criterion
    {
        $decimal = array_filter($values, \is_float(...)) === $values;
        $criterion = CriterionFactory::createOne([
            'code' => $code,
            'label' => $code,
            'valueType' => $decimal ? CriterionValueType::Decimal : CriterionValueType::Integer,
            'rankFromLowest' => $rankFromLowest,
        ]);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);
        $index = 0;
        foreach ($values as $name => $value) {
            $uniteUrbaine = UniteUrbaineFactory::createOne(['inseeCode' => \sprintf('%05d', ++$index), 'name' => (string) $name]);
            $entityManager->persist(new CriterionValue($criterion, $uniteUrbaine, $value, 2023));
        }

        $entityManager->flush();

        return $criterion;
    }
}
