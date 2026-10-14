<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Entity\Poi;
use App\Enum\TransitStopMode;
use App\Repository\PoiCategoryRepository;
use App\Repository\PoiRepository;
use App\Service\PoiUniteUrbaineResolver;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reads the vendored `resources/geo/arrets-tram-metro.csv` (see
 * TransitStopSourceDownloader) and upserts its stations as POIs, one category
 * per mode, matched on the source's id. Idempotent: re-running updates in
 * place, never duplicates, and drops the stations the source no longer lists.
 * Needs the zones imported first, to attach each station to its unité urbaine.
 */
final readonly class TransitStopImporter
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PoiCategoryRepository $poiCategoryRepository,
        private PoiCategoryTreeSynchronizer $poiCategoryTreeSynchronizer,
        private PoiRepository $poiRepository,
        private PoiUniteUrbaineResolver $uniteUrbaineResolver,
        private CsvReader $csvReader,
    ) {
    }

    /**
     * @return int number of stations imported
     */
    public function import(string $csvPath): int
    {
        $this->poiCategoryTreeSynchronizer->synchronize();
        $categories = [];
        foreach (TransitStopMode::cases() as $mode) {
            $categories[$mode->value] = $this->poiCategoryRepository->getByCode($mode->categoryCode());
        }

        /** @var array<string, Poi> $stale */
        $stale = [];
        foreach ($categories as $category) {
            foreach ($this->poiRepository->findByCategoryWithUniteUrbaine($category) as $poi) {
                $stale[(string) $poi->getExternalId()] = $poi;
            }
        }

        $count = 0;
        foreach ($this->csvReader->read($csvPath) as $row) {
            $category = $categories[TransitStopMode::from($row['mode'])->value];

            // EWKT is longitude first, while the source gives the latitude first.
            $location = \sprintf('SRID=4326;POINT(%.7F %.7F)', (float) $row['lon'], (float) $row['lat']);

            $poi = $stale[$row['id']] ?? null;
            if (null === $poi) {
                $poi = new Poi($row['name'], $category, $location);
                $poi->setExternalId($row['id']);
                $this->entityManager->persist($poi);
            } else {
                $poi->setName($row['name']);
                $poi->setCategory($category);
                $poi->setLocation($location);
            }

            $this->uniteUrbaineResolver->resolve($poi);

            unset($stale[$row['id']]);
            ++$count;
        }

        foreach ($stale as $stalePoi) {
            $this->entityManager->remove($stalePoi);
        }

        $this->entityManager->flush();
        $this->entityManager->clear();

        return $count;
    }
}
