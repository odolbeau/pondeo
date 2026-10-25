<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Api\UniteUrbaineMapExportController;
use App\Service\Filter\FilterPanelDataProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/carte', name: 'app_map')]
final class UniteUrbaineMapController extends AbstractController
{
    public function __construct(private readonly FilterPanelDataProvider $filterPanelDataProvider)
    {
    }

    public function __invoke(): Response
    {
        return $this->render('map/index.html.twig', [
            'title' => 'Carte des unités urbaines',
            'dataUrl' => $this->generateUrl('app_map_data'),
            'exportUrl' => $this->generateUrl('app_map_export'),
            'exportLimit' => UniteUrbaineMapExportController::LIMIT,
            'plantsUrl' => $this->generateUrl('app_map_nuclear_plants'),
        ] + $this->filterPanelDataProvider->provide());
    }
}
