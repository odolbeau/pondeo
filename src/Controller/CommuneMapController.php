<?php

declare(strict_types=1);

namespace App\Controller;

use App\Enum\ZoneType;
use App\Service\Map\ZoneMapDataProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/carte/communes', name: 'app_map_communes')]
final class CommuneMapController extends AbstractController
{
    public function __construct(
        private readonly ZoneMapDataProvider $zoneMapDataProvider,
    ) {
    }

    public function __invoke(): Response
    {
        $period = $this->zoneMapDataProvider->getLatestPeriod(ZoneType::Commune);
        $bounds = $this->zoneMapDataProvider->getPopulationBounds(ZoneType::Commune, $period);

        return $this->render('map/index.html.twig', [
            'title' => 'Carte des communes',
            'dataUrl' => $this->generateUrl('app_map_communes_data'),
            'populationMin' => $bounds['min'],
            'populationMax' => $bounds['max'],
        ]);
    }
}
