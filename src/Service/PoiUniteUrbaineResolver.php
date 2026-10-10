<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Poi;
use App\Repository\UniteUrbaineRepository;

/**
 * Resolves and sets a POI's containing unité urbaine — called explicitly
 * whenever a POI is created or its location changes (e.g. by the future
 * import pipeline), not on every read. A POI outside every unité urbaine
 * is legitimate (rural), so the result may be null.
 */
final readonly class PoiUniteUrbaineResolver
{
    public function __construct(private UniteUrbaineRepository $uniteUrbaineRepository)
    {
    }

    public function resolve(Poi $poi): void
    {
        $poi->setUniteUrbaine($this->uniteUrbaineRepository->findContaining($poi->getLocation()));
    }
}
