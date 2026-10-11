<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Enum\Departement;
use App\Factory\UniteUrbaineFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class UniteUrbaineSearchIndexControllerTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    public function testReturnsEveryUnitWithItsDepartement(): void
    {
        $client = self::createClient();

        UniteUrbaineFactory::createOne(['inseeCode' => '00002', 'name' => 'Rennes', 'departement' => Departement::IlleEtVilaine]);
        UniteUrbaineFactory::createOne(['inseeCode' => '00001', 'name' => 'Orléans', 'departement' => null]);

        $client->request('GET', '/recherche/index');

        self::assertResponseIsSuccessful();
        self::assertSame(
            [['00001', 'Orléans', null], ['00002', 'Rennes', 'Ille-et-Vilaine']],
            json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR),
        );
    }

    public function testNavigationHoldsTheSearchBox(): void
    {
        $client = self::createClient();

        $client->request('GET', '/criteres');

        self::assertSelectorExists('nav[data-controller="nav"] button[aria-controls="nav-panel"][aria-expanded="false"]');
        self::assertSelectorExists('nav #nav-panel [data-controller="search"][data-search-index-url-value="/recherche/index"][data-search-detail-url-value="/unites-urbaines/XXXXX"]');
    }
}
