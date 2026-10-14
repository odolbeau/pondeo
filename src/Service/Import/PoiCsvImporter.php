<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Entity\Poi;
use App\Repository\PoiCategoryRepository;
use App\Repository\PoiRepository;
use App\Service\PoiUniteUrbaineResolver;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reads a vendored file of POIs of one category (columns `id,name,lat,lon,
 * address,activity`) and upserts its rows, matched on the source's identifier.
 * Idempotent: re-running updates in place, never duplicates, and drops the
 * POIs the file no longer lists. Needs the zones imported first, to attach
 * each POI to its unité urbaine.
 */
final readonly class PoiCsvImporter
{
    // The larger files (tens of thousands of POIs) would not fit in memory as one unit of work.
    private const int BATCH_SIZE = 500;

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
     * @return int number of POIs imported
     */
    public function import(string $csvPath, string $categoryCode): int
    {
        $this->poiCategoryTreeSynchronizer->synchronize();
        $category = $this->poiCategoryRepository->getByCode($categoryCode);

        /** @var array<string, Poi> $stale */
        $stale = [];
        foreach ($this->poiRepository->findByCategoryWithUniteUrbaine($category) as $poi) {
            $stale[(string) $poi->getExternalId()] = $poi;
        }

        $count = 0;
        /** @var list<Poi> $batch */
        $batch = [];
        foreach ($this->csvReader->read($csvPath) as $row) {
            // EWKT is longitude first, while the source gives the latitude first.
            $location = \sprintf('SRID=4326;POINT(%.7F %.7F)', (float) $row['lon'], (float) $row['lat']);

            $poi = $stale[$row['id']] ?? null;
            if (null === $poi) {
                $poi = new Poi($row['name'], $category, $location);
                $poi->setExternalId($row['id']);
                $this->entityManager->persist($poi);
            } else {
                $poi->setName($row['name']);
                $poi->setLocation($location);
            }

            $poi->setAddress('' === $row['address'] ? null : $row['address']);
            $poi->setAttributes('' === $row['activity'] ? null : ['activity' => $row['activity']]);

            $this->uniteUrbaineResolver->resolve($poi);

            unset($stale[$row['id']]);
            ++$count;

            $batch[] = $poi;
            if (self::BATCH_SIZE === \count($batch)) {
                $this->flushBatch($batch);
                $batch = [];
            }
        }

        $this->flushBatch($batch);

        foreach ($stale as $stalePoi) {
            $this->entityManager->remove($stalePoi);
        }

        $this->entityManager->flush();
        $this->entityManager->clear();

        return $count;
    }

    /**
     * @param list<Poi> $batch
     */
    private function flushBatch(array $batch): void
    {
        $this->entityManager->flush();

        foreach ($batch as $poi) {
            $this->entityManager->detach($poi);
        }
    }
}
