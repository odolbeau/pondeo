<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Service\Map\UniteUrbaineMapDataProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

/**
 * GeoJSON FeatureCollection of unités urbaines matching the sidebar filters.
 * A new filter is a new query param read here, not a new controller.
 */
#[Route('/carte/donnees', name: 'app_map_data')]
final class UniteUrbaineMapDataController extends AbstractController
{
    public function __construct(
        private readonly UniteUrbaineMapDataProvider $mapDataProvider,
    ) {
    }

    public function __invoke(#[MapQueryString] UniteUrbaineMapDataInput $filters): JsonResponse
    {
        $period = $this->mapDataProvider->getLatestPeriod();
        $bounds = $this->mapDataProvider->getPopulationBounds($period);

        $min = $filters->populationMin ?? $bounds['min'];
        $max = $filters->populationMax ?? $bounds['max'];

        $unitesUrbaines = $this->mapDataProvider->findFeatures($period, $min, $max, $filters->excludedAttractionCategories(), $filters->commerceDensityMin, $filters->nuclearExclusionKm, $filters->priceMax, $filters->withStation, $filters->trainMaxMinutes, $filters->trainMaxTransfers);

        return JsonResponse::fromJsonString($this->mapDataProvider->toFeatureCollectionJson($unitesUrbaines));
    }
}
