<?php

declare(strict_types=1);

namespace App\Tests\Service\Ranking;

use App\Entity\CriterionValue;
use App\Entity\UniteUrbaine;
use App\Enum\CriterionValueType;
use App\Factory\CriterionFactory;
use App\Factory\UniteUrbaineFactory;
use App\Service\Ranking\WeightedScoreProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class WeightedScoreProviderTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function testWeightsDecideWhichCriterionWins(): void
    {
        $this->createValues('sun', ['A' => 100, 'B' => 200, 'C' => 300]);
        $this->createValues('rent', ['A' => 10, 'B' => 20, 'C' => 30], rankFromLowest: true);

        $sunny = $this->provider()->findScores(['sun' => 5, 'rent' => 1], 10);
        $cheap = $this->provider()->findScores(['sun' => 1, 'rent' => 5], 10);

        self::assertSame(['C', 'B', 'A'], array_column($sunny['rows'], 'name'));
        self::assertSame(['A', 'B', 'C'], array_column($cheap['rows'], 'name'));
        self::assertSame(3, $sunny['total']);
        // C is the best at sun (1) and the worst at rent (0): (5 × 1 + 1 × 0) / 6.
        self::assertEqualsWithDelta(83.3, $sunny['rows'][0]['score'], 0.01);
    }

    public function testNoWeightMeansNoRanking(): void
    {
        $this->createValues('sun', ['A' => 100]);

        self::assertSame(['total' => 0, 'rows' => []], $this->provider()->findScores([], 10));
    }

    public function testUnitWithoutEnoughDataIsLeftOut(): void
    {
        $this->createValues('sun', ['A' => 100, 'B' => 200, 'C' => 300]);
        $this->createValues('rent', ['A' => 10, 'B' => 20], rankFromLowest: true);

        // C has no rent: it holds 1 of 4 weight points, under the half.
        $scores = $this->provider()->findScores(['sun' => 1, 'rent' => 3], 10);
        self::assertSame(['A', 'B'], array_column($scores['rows'], 'name'));

        // With the weights balanced, it holds half and is ranked on the sun alone.
        $balanced = $this->provider()->findScores(['sun' => 1, 'rent' => 1], 10);
        self::assertContains('C', array_column($balanced['rows'], 'name'));
    }

    public function testTiesShareARankAndPagesAreCut(): void
    {
        $this->createValues('sun', ['A' => 100, 'B' => 100, 'C' => 300]);

        $page = $this->provider()->findScores(['sun' => 1], 2);
        self::assertSame([1, 2], array_column($page['rows'], 'rank'));

        $next = $this->provider()->findScores(['sun' => 1], 2, 2);
        self::assertSame([2], array_column($next['rows'], 'rank'));
        self::assertSame(3, $next['total']);
    }

    public function testStrengthsAreTheCriteriaWhereTheUnitIsAmongTheBest(): void
    {
        $this->createValues('sun', ['A' => 100, 'B' => 200, 'C' => 300]);
        $this->createValues('rent', ['A' => 30, 'B' => 20, 'C' => 10], rankFromLowest: true);

        $rows = $this->provider()->findScores(['sun' => 1, 'rent' => 1], 10)['rows'];
        $byName = array_column($rows, 'strengths', 'name');

        self::assertSame(['rent', 'sun'], $byName['C']);
        self::assertSame([], $byName['A']);
    }

    private function provider(): WeightedScoreProvider
    {
        $provider = self::getContainer()->get(WeightedScoreProvider::class);
        \assert($provider instanceof WeightedScoreProvider);

        return $provider;
    }

    /**
     * @param array<string, int|float> $values unité urbaine name => value (2023)
     */
    private function createValues(string $code, array $values, bool $rankFromLowest = false): void
    {
        $criterion = CriterionFactory::createOne([
            'code' => $code,
            'label' => $code,
            'valueType' => CriterionValueType::Integer,
            'rankFromLowest' => $rankFromLowest,
        ]);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);
        $index = 0;
        foreach ($values as $name => $value) {
            $uniteUrbaine = $entityManager->getRepository(UniteUrbaine::class)->findOneBy(['name' => (string) $name])
                ?? UniteUrbaineFactory::createOne(['inseeCode' => \sprintf('%05d', ++$index), 'name' => (string) $name]);
            $entityManager->persist(new CriterionValue($criterion, $uniteUrbaine, $value, 2023));
        }

        $entityManager->flush();
    }
}
