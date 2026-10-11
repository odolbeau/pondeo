<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Enum\CriterionValueType;
use App\Enum\Departement;
use App\Factory\CriterionFactory;
use App\Factory\UniteUrbaineFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class SeoControllerTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    public function testSitemapListsThePagesRankingsAndUnits(): void
    {
        $client = self::createClient();
        UniteUrbaineFactory::createOne(['inseeCode' => '35701', 'name' => 'Rennes', 'departement' => Departement::IlleEtVilaine]);

        $client->request('GET', '/sitemap.xml');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/xml');
        $xml = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('<loc>http://localhost/classements</loc>', $xml);
        self::assertStringContainsString('<loc>http://localhost/classements/population</loc>', $xml);
        self::assertStringContainsString('<loc>http://localhost/unites-urbaines/35701</loc>', $xml);
    }

    public function testRobotsPointsToTheSitemap(): void
    {
        $client = self::createClient();

        $client->request('GET', '/robots.txt');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Sitemap: http://localhost/sitemap.xml', (string) $client->getResponse()->getContent());
    }

    public function testPagesHaveTheirOwnDescriptionAndCanonicalUrl(): void
    {
        $client = self::createClient();
        UniteUrbaineFactory::createOne(['inseeCode' => '35701', 'name' => 'Rennes', 'departement' => Departement::IlleEtVilaine]);
        CriterionFactory::createOne(['code' => 'population', 'label' => 'Population', 'valueType' => CriterionValueType::Integer]);

        $client->request('GET', '/classements/population?ordre=inverse');

        self::assertSelectorExists('meta[name="description"][content*="Les plus peuplées"]');
        self::assertSelectorExists('link[rel="canonical"][href="http://localhost/classements/population"]');
        self::assertSelectorExists('meta[property="og:title"][content*="Les plus peuplées"]');

        $client->request('GET', '/unites-urbaines/35701');

        self::assertSelectorExists('meta[name="description"][content*="Rennes (Ille-et-Vilaine)"]');
    }
}
