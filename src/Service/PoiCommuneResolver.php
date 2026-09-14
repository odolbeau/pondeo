<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Poi;
use App\Entity\PoiCommuneLocation;
use App\Repository\CommuneRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Resolves and persists a POI's containing commune (PoiCommuneLocation) —
 * called explicitly whenever a POI is created or its location changes
 * (e.g. by the future import pipeline), not on every read. See plan §4.
 */
final readonly class PoiCommuneResolver
{
    public function __construct(
        private CommuneRepository $communeRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function resolve(Poi $poi): PoiCommuneLocation
    {
        $commune = $this->communeRepository->findContaining($poi->getLocation());
        if (null === $commune) {
            throw new \LogicException(\sprintf('No commune contains POI "%s" (%s).', $poi->getName(), $poi->getLocation()));
        }

        $existing = $this->entityManager->getRepository(PoiCommuneLocation::class)->findOneBy(['poi' => $poi]);
        if (null !== $existing) {
            $existing->setCommune($commune);

            return $existing;
        }

        $location = new PoiCommuneLocation($poi, $commune);
        $this->entityManager->persist($location);

        return $location;
    }
}
