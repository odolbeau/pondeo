<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Service\Map\UniteUrbaineMapDataProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/** GeoJSON FeatureCollection of the nuclear plants, for the map markers. */
#[Route('/carte/centrales', name: 'app_map_nuclear_plants')]
final class NuclearPlantsController extends AbstractController
{
    public function __construct(
        private readonly UniteUrbaineMapDataProvider $mapDataProvider,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        return JsonResponse::fromJsonString($this->mapDataProvider->findNuclearPlantsJson());
    }
}
