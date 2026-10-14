<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Entity\Poi;
use App\Repository\PoiCategoryRepository;
use App\Repository\PoiRepository;
use App\Service\PoiUniteUrbaineResolver;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reads the vendored `resources/geo/gares-voyageurs.csv` (see
 * StationSourceDownloader) and upserts the stations as POIs of the
 * "railway_station" category, matched on the source's station id. Idempotent:
 * re-running updates in place, never duplicates, and drops the stations the
 * source no longer lists. Needs the zones imported first, to attach each
 * station to its unité urbaine.
 */
final readonly class StationImporter
{
    public const string CATEGORY_CODE = 'railway_station';

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
        $category = $this->poiCategoryRepository->getByCode(self::CATEGORY_CODE);

        /** @var array<string, Poi> $stale */
        $stale = [];
        foreach ($this->poiRepository->findByCategoryWithUniteUrbaine($category) as $poi) {
            $stale[(string) $poi->getExternalId()] = $poi;
        }

        $count = 0;
        foreach ($this->csvReader->read($csvPath) as $row) {
            // EWKT is longitude first, while the source gives the latitude first.
            $location = \sprintf('SRID=4326;POINT(%.7F %.7F)', (float) $row['lon'], (float) $row['lat']);

            $poi = $stale[$row['id_gare']] ?? null;
            if (null === $poi) {
                $poi = new Poi($row['name'], $category, $location);
                $poi->setExternalId($row['id_gare']);
                $this->entityManager->persist($poi);
            } else {
                $poi->setName($row['name']);
                $poi->setLocation($location);
            }

            $this->uniteUrbaineResolver->resolve($poi);

            unset($stale[$row['id_gare']]);
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
