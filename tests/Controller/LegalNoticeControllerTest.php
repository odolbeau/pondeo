<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class LegalNoticeControllerTest extends WebTestCase
{
    public function testShowsThePublisherAndTheHost(): void
    {
        $client = self::createClient();
        $client->request('GET', '/mentions-legales');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Mentions légales');
        self::assertAnySelectorTextContains('h2', 'Éditeur');
        self::assertAnySelectorTextContains('h2', 'Hébergeur');
        self::assertSelectorTextContains('main', 'Scaleway');
    }

    public function testTheFooterLinksToTheLegalNotice(): void
    {
        $client = self::createClient();
        $client->request('GET', '/sources');

        self::assertSelectorExists('footer a[href="/mentions-legales"]');
    }
}
