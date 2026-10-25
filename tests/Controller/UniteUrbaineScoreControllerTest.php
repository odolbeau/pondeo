<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CriterionValue;
use App\Enum\CriterionValueType;
use App\Factory\CriterionFactory;
use App\Factory\UniteUrbaineFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class UniteUrbaineScoreControllerTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    public function testPageOffersASliderPerRankingAndNoResultWithoutWeight(): void
    {
        $client = self::createClient();

        $client->request('GET', '/mon-classement');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[type=range][name="w[taux_chomage]"]');
        self::assertSelectorTextContains('turbo-frame#score-results', 'Réglez au moins un critère');
        self::assertSelectorNotExists('tbody');
    }

    public function testUnitsAreRankedByTheirWeightedScore(): void
    {
        $client = self::createClient();
        $this->createValues('taux_activite', ['Petite' => 60.0, 'Grande' => 80.0, 'Moyenne' => 70.0]);
        $this->createValues('taux_chomage', ['Petite' => 5.0, 'Grande' => 12.0, 'Moyenne' => 8.0], rankFromLowest: true);

        $client->request('GET', '/mon-classement?w[taux_activite]=5&w[taux_chomage]=1');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('tbody tr:first-child', 'Grande');
        self::assertSelectorTextContains('tbody tr:first-child', 'Points forts : les plus actives');
        self::assertSelectorTextContains('tbody tr:last-child', 'Petite');

        $client->request('GET', '/mon-classement?w[taux_activite]=1&w[taux_chomage]=5');
        self::assertSelectorTextContains('tbody tr:first-child', 'Petite');
    }

    public function testCheapPricesScoreHigherAndPopulationIsNotAnOption(): void
    {
        $client = self::createClient();
        $this->createValues('prix_m2', ['Chere' => 5000, 'Abordable' => 1500]);

        $client->request('GET', '/mon-classement?w[prix_m2]=3');

        self::assertSelectorTextContains('tbody tr:first-child', 'Abordable');
        self::assertSelectorTextContains('tbody tr:first-child', 'Points forts : des prix au m² bas');
        self::assertSelectorNotExists('input[name="w[population]"]');
    }

    public function testFilterKeepsSomeUnitsRankedAmongThemselvesWithTheirNationalPercentiles(): void
    {
        $client = self::createClient();
        $this->createValues('taux_activite', ['Petite' => 60.0, 'Grande' => 80.0, 'Moyenne' => 70.0]);
        $this->createValues('population', ['Petite' => 5_000, 'Grande' => 500_000, 'Moyenne' => 50_000]);

        $crawler = $client->request('GET', '/mon-classement?w[taux_activite]=5&populationMax=100000');

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(2, 'tbody tr');
        self::assertSelectorTextContains('tbody tr:first-child', 'Moyenne');
        self::assertSelectorTextContains('tbody tr:first-child td:first-child', '1');
        // Moyenne is at the median of the three units, whatever the filter: 50 out of 100.
        self::assertSelectorTextContains('tbody tr:first-child', '50,0');
        self::assertSelectorTextContains('tbody tr:last-child td:first-child', '2');
        self::assertSelectorTextContains('tbody tr:last-child', 'Petite');
        self::assertCount(0, $crawler->filter('a:contains("Grande")'));
    }

    public function testZeroWeightsAndUnknownCriteriaAreIgnored(): void
    {
        $client = self::createClient();
        $this->createValues('taux_chomage', ['Petite' => 5.0, 'Grande' => 12.0]);

        $client->request('GET', '/mon-classement?w[taux_chomage]=0&w[inconnu]=3');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('tbody');
    }

    public function testWeightOutOfRangeIsRejected(): void
    {
        $client = self::createClient();

        $client->request('GET', '/mon-classement?w[taux_chomage]=9');

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @param array<string, int|float> $values unité urbaine name => value (2023)
     */
    private function createValues(string $code, array $values, bool $rankFromLowest = false): void
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
        foreach ($values as $name => $value) {
            $uniteUrbaine = UniteUrbaineFactory::new()->findOrCreate(['name' => (string) $name]);
            $entityManager->persist(new CriterionValue($criterion, $uniteUrbaine, $value, 2023));
        }

        $entityManager->flush();
    }
}
