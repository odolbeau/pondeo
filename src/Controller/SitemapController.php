<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\UniteUrbaineRepository;
use App\Service\Ranking\RankingCatalog;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/sitemap.xml', name: 'app_sitemap', format: 'xml')]
final class SitemapController extends AbstractController
{
    public function __construct(
        private readonly UniteUrbaineRepository $uniteUrbaineRepository,
        private readonly RankingCatalog $catalog,
    ) {
    }

    public function __invoke(): Response
    {
        $response = $this->render('seo/sitemap.xml.twig', [
            'rankingCodes' => $this->catalog->codes(),
            'uniteUrbaineCodes' => array_column($this->uniteUrbaineRepository->findSearchIndex(), 'code'),
        ]);
        $response->headers->set('Content-Type', 'application/xml');
        $response->setPublic();
        $response->setMaxAge(3600);

        return $response;
    }
}
