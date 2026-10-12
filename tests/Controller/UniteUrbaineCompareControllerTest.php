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

final class UniteUrbaineCompareControllerTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    public function testTwoUnitsAreComparedSideBySideWithTheBestValueHighlighted(): void
    {
        $client = self::createClient();

        $vannes = UniteUrbaineFactory::createOne(['inseeCode' => '56501', 'name' => 'Vannes']);
        $nantes = UniteUrbaineFactory::createOne(['inseeCode' => '44701', 'name' => 'Nantes']);
        $population = CriterionFactory::createOne(['code' => 'population', 'label' => 'Population', 'unit' => 'hab.', 'valueType' => CriterionValueType::Integer, 'groupLabel' => 'Démographie']);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);
        $entityManager->persist(new CriterionValue($population, $vannes, 84_176, 2023));
        $entityManager->persist(new CriterionValue($population, $nantes, 500_000, 2023));
        $entityManager->flush();

        $client->request('GET', '/comparer?villes=56501,44701');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('thead', 'Vannes');
        self::assertSelectorTextContains('thead', 'Nantes');
        self::assertSelectorTextContains('tbody th[scope=colgroup]', 'Démographie');
        self::assertSelectorTextContains('tbody tr:nth-child(2) td:nth-of-type(1)', "84\u{a0}176");
        self::assertSelectorTextContains('tbody tr:nth-child(2) td.font-semibold', "500\u{a0}000");
    }

    public function testASingleUnitAsksForAnother(): void
    {
        $client = self::createClient();
        UniteUrbaineFactory::createOne(['inseeCode' => '56501', 'name' => 'Vannes']);

        $client->request('GET', '/comparer?villes=56501');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Ajoutez au moins deux villes');
        self::assertSelectorNotExists('table');
    }

    public function testUnknownCodeIs404(): void
    {
        $client = self::createClient();
        UniteUrbaineFactory::createOne(['inseeCode' => '56501']);

        $client->request('GET', '/comparer?villes=56501,99999');

        self::assertResponseStatusCodeSame(404);
    }

    public function testMoreThanFourUnitsIsRejected(): void
    {
        $client = self::createClient();

        $client->request('GET', '/comparer?villes=11111,22222,33333,44444,55555');

        self::assertResponseStatusCodeSame(404);
    }
}
