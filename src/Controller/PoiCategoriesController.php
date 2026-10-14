<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\PoiCategoryRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/categories-poi', name: 'app_poi_categories')]
final class PoiCategoriesController extends AbstractController
{
    public function __construct(private readonly PoiCategoryRepository $poiCategoryRepository)
    {
    }

    public function __invoke(): Response
    {
        return $this->render('poi_categories/index.html.twig', ['roots' => $this->poiCategoryRepository->findRoots()]);
    }
}
