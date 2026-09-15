<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Enum\ZoneType;
use App\Service\Map\ZoneMapDataProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

/**
 * GeoJSON FeatureCollection of communes matching the sidebar filters.
 * Population is the only filter today; a future criterion is a new query
 * param read here, not a new controller.
 */
#[Route('/carte/communes/donnees', name: 'app_map_communes_data')]
final class CommuneMapDataController extends AbstractController
{
    public function __construct(
        private readonly ZoneMapDataProvider $zoneMapDataProvider,
    ) {
    }

    public function __invoke(#[MapQueryString] CommuneMapDataInput $filters): JsonResponse
    {
        $period = $this->zoneMapDataProvider->getLatestPeriod(ZoneType::Commune);
        $bounds = $this->zoneMapDataProvider->getPopulationBounds(ZoneType::Commune, $period);

        $min = $filters->populationMin ?? $bounds['min'];
        $max = $filters->populationMax ?? $bounds['max'];

        $communes = $this->zoneMapDataProvider->findFeatures(ZoneType::Commune, $period, $min, $max);

        return JsonResponse::fromJsonString($this->zoneMapDataProvider->toFeatureCollectionJson($communes));
    }
}
