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

final class HomeControllerTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    public function testHomepageReturns200(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/carte"]');
        self::assertSelectorExists('a[href="/classements"]');
    }

    public function testHomepageShowsStatsAndFeaturedRankings(): void
    {
        $client = self::createClient();
        $vannes = UniteUrbaineFactory::createOne(['inseeCode' => '56501', 'name' => 'Vannes']);
        $population = CriterionFactory::createOne(['code' => 'population', 'label' => 'Population', 'valueType' => CriterionValueType::Integer]);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);
        $entityManager->persist(new CriterionValue($population, $vannes, 84_176, 2023));
        $entityManager->flush();

        $client->request('GET', '/');

        self::assertSelectorTextContains('dl', 'Unités urbaines');
        self::assertSelectorExists('a[href="/classements/population"]');
        self::assertSelectorTextContains('main', 'Vannes');
    }
}
