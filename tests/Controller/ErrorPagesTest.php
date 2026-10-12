<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

final class ErrorPagesTest extends WebTestCase
{
    public function testUnknownUrlRendersTheStyledNotFoundPage(): void
    {
        $client = self::createClient(['debug' => false]);
        $client->request('GET', '/cette-page-n-existe-pas');

        self::assertResponseStatusCodeSame(404);
        self::assertSelectorTextContains('h1', "Cette page n'existe pas");
        self::assertSelectorExists('a[href="/classements"]');
        self::assertSelectorExists('[data-controller="search"]');
    }

    public function testGenericServerErrorPageHasNoSearchNorRankingsLink(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $requestStack = $container->get(RequestStack::class);
        $twig = $container->get(Environment::class);
        \assert($requestStack instanceof RequestStack && $twig instanceof Environment);

        $requestStack->push(Request::create('/boom'));
        $html = $twig->render('@Twig/Exception/error.html.twig', ['status_code' => 500, 'status_text' => 'Internal Server Error']);

        self::assertStringContainsString('Erreur 500', $html);
        self::assertStringContainsString('c\'est de notre côté', $html);
        self::assertStringNotContainsString('data-controller="search"', $html);
        self::assertStringNotContainsString('/classements', $html);
    }
}
