<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SourcesControllerTest extends WebTestCase
{
    public function testListsTheSourcesWithTheirLicence(): void
    {
        $client = self::createClient();
        $client->request('GET', '/sources');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Sources des données');
        self::assertAnySelectorTextContains('h2', 'Insee');
        self::assertAnySelectorTextContains('h2', 'Open-Meteo');
        self::assertAnySelectorTextContains('li p', 'CC BY 4.0');
        self::assertAnySelectorTextContains('h2', 'Subverti');
        self::assertAnySelectorTextContains('li p', "avec l'autorisation de Subverti");
    }

    public function testTheFooterLinksToTheSourcesPage(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');

        self::assertSelectorExists('footer a[href="/sources"]');
    }
}
