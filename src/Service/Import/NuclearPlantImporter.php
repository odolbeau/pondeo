<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Entity\Poi;
use App\Repository\PoiCategoryRepository;
use App\Repository\PoiRepository;
use App\Service\PoiUniteUrbaineResolver;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reads the vendored `resources/geo/centrales-nucleaires.csv` (see
 * NuclearPlantSourceDownloader) and upserts the plants as POIs of the
 * "nuclear_power_plant" category, matched on the source's plant id.
 * Idempotent: re-running updates in place, never duplicates, and drops the
 * plants the source no longer lists. Needs the zones imported first, to
 * attach each plant to its unité urbaine.
 */
final readonly class NuclearPlantImporter
{
    public const string CATEGORY_CODE = 'nuclear_power_plant';

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
     * @return int number of plants imported
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
            $attributes = ['reactors' => (int) $row['reactors'], 'powerMw' => (int) $row['power_mw']];

            $poi = $stale[$row['id']] ?? null;
            if (null === $poi) {
                $poi = new Poi($row['name'], $category, $location);
                $poi->setExternalId($row['id']);
                $this->entityManager->persist($poi);
            } else {
                $poi->setName($row['name']);
                $poi->setLocation($location);
            }

            $poi->setAttributes($attributes);

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
