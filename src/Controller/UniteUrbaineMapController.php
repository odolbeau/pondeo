<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Map\UniteUrbaineMapDataProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/carte', name: 'app_map')]
final class UniteUrbaineMapController extends AbstractController
{
    public function __construct(
        private readonly UniteUrbaineMapDataProvider $mapDataProvider,
    ) {
    }

    public function __invoke(): Response
    {
        $period = $this->mapDataProvider->getLatestPeriod();
        $bounds = $this->mapDataProvider->getPopulationBounds($period);

        return $this->render('map/index.html.twig', [
            'title' => 'Carte des unités urbaines',
            'dataUrl' => $this->generateUrl('app_map_data'),
            'populationMin' => $bounds['min'],
            'populationMax' => $bounds['max'],
        ]);
    }
}
