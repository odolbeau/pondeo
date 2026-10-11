<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

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

final class UniteUrbaineCriterionSeriesControllerTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    public function testSeriesHoldsTheUnitValuesNextToTheMedianOfEveryUnitPerPeriod(): void
    {
        $client = self::createClient();

        $vannes = UniteUrbaineFactory::createOne(['inseeCode' => '56501']);
        $other = UniteUrbaineFactory::createOne(['inseeCode' => '44701']);
        $third = UniteUrbaineFactory::createOne(['inseeCode' => '29201']);
        $criterion = CriterionFactory::createOne(['code' => 'densite', 'label' => 'Densité', 'unit' => 'hab./km²', 'valueType' => CriterionValueType::Decimal]);

        // Vannes has no 2007 value, the other units do: the series is aligned on all periods.
        $this->addValues($criterion, $vannes, [2006 => 100.5, 2008 => 120.0]);
        $this->addValues($criterion, $other, [2006 => 10.0, 2007 => 20.0, 2008 => 30.0]);
        $this->addValues($criterion, $third, [2006 => 30.0, 2007 => 40.0, 2008 => 50.0]);

        $client->request('GET', '/unites-urbaines/56501/criteres/densite/serie');

        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString(
            json_encode([
                'label' => 'Densité',
                'unit' => 'hab./km²',
                'periods' => [2006, 2007, 2008],
                'values' => [100.5, null, 120.0],
                'medians' => [30.0, 30.0, 50.0],
            ], \JSON_THROW_ON_ERROR),
            (string) $client->getResponse()->getContent(),
        );
    }

    public function testSeriesOfANonComparableCriterionHasNoMedian(): void
    {
        $client = self::createClient();

        $vannes = UniteUrbaineFactory::createOne(['inseeCode' => '56501']);
        $criterion = CriterionFactory::createOne(['code' => 'commerces_total', 'valueType' => CriterionValueType::Integer, 'comparable' => false]);
        $this->addValues($criterion, $vannes, [2024 => 300, 2025 => 310]);

        $client->request('GET', '/unites-urbaines/56501/criteres/commerces_total/serie');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertNull($data['medians']);
        self::assertEquals([300, 310], $data['values']);
    }

    public function testSeriesIsNotFoundWithoutAValueForTheUnitOrForANonNumericCriterion(): void
    {
        $client = self::createClient();

        $vannes = UniteUrbaineFactory::createOne(['inseeCode' => '56501']);
        $other = UniteUrbaineFactory::createOne(['inseeCode' => '44701']);
        $numeric = CriterionFactory::createOne(['code' => 'densite', 'valueType' => CriterionValueType::Decimal]);
        $text = CriterionFactory::createOne(['code' => 'climat', 'valueType' => CriterionValueType::Text]);
        $this->addValues($numeric, $other, [2006 => 10.0]);
        $this->addValues($text, $vannes, [2006 => 'océanique']);

        $client->request('GET', '/unites-urbaines/56501/criteres/densite/serie');
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/unites-urbaines/56501/criteres/climat/serie');
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/unites-urbaines/56501/criteres/inconnu/serie');
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/unites-urbaines/99999/criteres/densite/serie');
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @param array<int, int|float|string> $valuesByPeriod
     */
    private function addValues(Criterion $criterion, UniteUrbaine $uniteUrbaine, array $valuesByPeriod): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);

        foreach ($valuesByPeriod as $period => $value) {
            $entityManager->persist(new CriterionValue($criterion, $uniteUrbaine, $value, $period));
        }

        $entityManager->flush();
    }
}
