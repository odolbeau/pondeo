<?php

declare(strict_types=1);

namespace App\Controller;

use App\Enum\ZoneType;
use App\Service\Map\ZoneMapDataProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/carte/epci', name: 'app_map_epci')]
final class EpciMapController extends AbstractController
{
    public function __construct(
        private readonly ZoneMapDataProvider $zoneMapDataProvider,
    ) {
    }

    public function __invoke(): Response
    {
        $period = $this->zoneMapDataProvider->getLatestPeriod(ZoneType::Epci);
        $bounds = $this->zoneMapDataProvider->getPopulationBounds(ZoneType::Epci, $period);

        return $this->render('map/index.html.twig', [
            'title' => 'Carte des intercommunalités',
            'dataUrl' => $this->generateUrl('app_map_epci_data'),
            'populationMin' => $bounds['min'],
            'populationMax' => $bounds['max'],
        ]);
    }
}
