<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Served by a controller rather than a static file: the sitemap line needs the absolute URL of the host. */
#[Route('/robots.txt', name: 'app_robots', format: 'txt')]
final class RobotsController extends AbstractController
{
    public function __invoke(): Response
    {
        $lines = [
            'User-agent: *',
            'Allow: /',
            // JSON endpoints feeding the pages, not content in themselves.
            'Disallow: /carte/donnees',
            'Disallow: /carte/centrales',
            'Disallow: /recherche/',
            'Disallow: /unites-urbaines/*/criteres/',
            '',
            'Sitemap: '.$this->generateUrl('app_sitemap', [], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL),
            '',
        ];

        return new Response(implode("\n", $lines), headers: ['Content-Type' => 'text/plain']);
    }
}
