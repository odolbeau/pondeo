<?php

declare(strict_types=1);

namespace App\Controller;

use App\Enum\JourneyDestination;
use App\Enum\RealEstatePriceType;
use App\Service\Map\CriterionStatsProvider;
use App\Service\Map\UniteUrbaineMapDataProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/carte', name: 'app_map')]
final class UniteUrbaineMapController extends AbstractController
{
    public function __construct(
        private readonly UniteUrbaineMapDataProvider $mapDataProvider,
        private readonly CriterionStatsProvider $criterionStatsProvider,
    ) {
    }

    public function __invoke(): Response
    {
        $period = $this->mapDataProvider->getLatestPeriod();
        $bounds = $this->mapDataProvider->getPopulationBounds($period);

        return $this->render('map/index.html.twig', [
            'title' => 'Carte des unités urbaines',
            'dataUrl' => $this->generateUrl('app_map_data'),
            'plantsUrl' => $this->generateUrl('app_map_nuclear_plants'),
            'populationMin' => $bounds['min'],
            'populationMax' => $bounds['max'],
            'stats' => $this->criterionStatsProvider->findStats([
                'commerces_total_pour_1000_hab',
                'restaurants_pour_1000_hab',
                ...array_map(static fn (RealEstatePriceType $type): string => $type->criterionCode(), RealEstatePriceType::cases()),
            ]),
            'journeyDestinations' => JourneyDestination::cases(),
        ]);
    }
}
