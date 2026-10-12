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
        self::assertSelectorExists('input[type=range][name="w[population]"]');
        self::assertSelectorTextContains('turbo-frame#score-results', 'Réglez au moins un critère');
        self::assertSelectorNotExists('tbody');
    }

    public function testUnitsAreRankedByTheirWeightedScore(): void
    {
        $client = self::createClient();
        $this->createValues('population', ['Petite' => 100, 'Grande' => 300, 'Moyenne' => 200]);
        $this->createValues('taux_chomage', ['Petite' => 5.0, 'Grande' => 12.0, 'Moyenne' => 8.0], rankFromLowest: true);

        $client->request('GET', '/mon-classement?w[population]=5&w[taux_chomage]=1');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('tbody tr:first-child', 'Grande');
        self::assertSelectorTextContains('tbody tr:first-child', 'Points forts : les plus peuplées');
        self::assertSelectorTextContains('tbody tr:last-child', 'Petite');

        $client->request('GET', '/mon-classement?w[population]=1&w[taux_chomage]=5');
        self::assertSelectorTextContains('tbody tr:first-child', 'Petite');
    }

    public function testZeroWeightsAndUnknownCriteriaAreIgnored(): void
    {
        $client = self::createClient();
        $this->createValues('population', ['Petite' => 100, 'Grande' => 300]);

        $client->request('GET', '/mon-classement?w[population]=0&w[inconnu]=3');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('tbody');
    }

    public function testWeightOutOfRangeIsRejected(): void
    {
        $client = self::createClient();

        $client->request('GET', '/mon-classement?w[population]=9');

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
