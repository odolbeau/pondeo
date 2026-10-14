<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Entity\Poi;
use App\Entity\PoiCategory;
use App\Enum\BoardGameGroup;
use App\Enum\BoardGameType;
use App\Repository\PoiCategoryRepository;
use App\Repository\PoiRepository;
use App\Service\PoiUniteUrbaineResolver;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reads a vendored board game file (`resources/geo/jeux-associations.csv` or
 * `jeux-boutiques.csv`) and upserts its rows as POIs, one category per type
 * under the group's parent category, matched on the source's slug. Idempotent:
 * re-running updates in place, never duplicates, moves a place whose type
 * changed to its new category, and drops the places the file no longer lists.
 * Needs the zones imported first, to attach each place to its unité urbaine.
 */
final readonly class BoardGamePoiImporter
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
     * @return int number of places imported
     */
    public function import(string $csvPath, BoardGameGroup $group): int
    {
        $this->poiCategoryTreeSynchronizer->synchronize();
        /** @var array<string, PoiCategory> $categories by type value */
        $categories = [];
        foreach ($group->types() as $type) {
            $categories[$type->value] = $this->poiCategoryRepository->getByCode($type->categoryCode());
        }

        // The parent too: it holds no place of its own, except the ones of an older layout.
        /** @var array<string, Poi> $stale */
        $stale = [];
        foreach ([...$categories, $this->poiCategoryRepository->getByCode($group->value)] as $category) {
            foreach ($this->poiRepository->findByCategoryWithUniteUrbaine($category) as $poi) {
                $stale[(string) $poi->getExternalId()] = $poi;
            }
        }

        $count = 0;
        foreach ($this->csvReader->read($csvPath) as $row) {
            $type = BoardGameType::from($row['type']);
            if ($group !== $type->group()) {
                throw new \LogicException(\sprintf('The type "%s" does not belong to "%s".', $type->value, $group->value));
            }

            $category = $categories[$type->value];

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

            $poi->setAddress($row['address'] ?? $row['city']);
            $poi->setAttributes('' === $row['website'] ? null : ['website' => $row['website']]);

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
