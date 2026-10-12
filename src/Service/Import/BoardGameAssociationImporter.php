<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Entity\Poi;
use App\Entity\PoiCategory;
use App\Repository\PoiCategoryRepository;
use App\Repository\PoiRepository;
use App\Service\PoiUniteUrbaineResolver;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reads the vendored `resources/geo/associations-jeux.csv` (see
 * BoardGameAssociationSourceDownloader) and upserts the associations as POIs
 * of the "board_game_association" category, matched on their slug in the
 * directory. Idempotent: re-running updates in place, never duplicates, and
 * drops the associations the source no longer lists. Needs the zones imported
 * first, to attach each association to its unité urbaine.
 */
final readonly class BoardGameAssociationImporter
{
    public const string CATEGORY_CODE = 'board_game_association';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private PoiCategoryRepository $poiCategoryRepository,
        private PoiRepository $poiRepository,
        private PoiUniteUrbaineResolver $uniteUrbaineResolver,
        private CsvReader $csvReader,
    ) {
    }

    /**
     * @return int number of associations imported
     */
    public function import(string $csvPath): int
    {
        $category = $this->findOrCreateCategory();

        /** @var array<string, Poi> $stale */
        $stale = [];
        foreach ($this->poiRepository->findByCategoryWithUniteUrbaine($category) as $poi) {
            $stale[(string) $poi->getExternalId()] = $poi;
        }

        $count = 0;
        foreach ($this->csvReader->read($csvPath) as $row) {
            // EWKT is longitude first, while the source gives the latitude first.
            $location = \sprintf('SRID=4326;POINT(%.7F %.7F)', (float) $row['lon'], (float) $row['lat']);
            $attributes = ['type' => $row['type'], 'url' => $row['url']];

            $poi = $stale[$row['id']] ?? null;
            if (null === $poi) {
                $poi = new Poi($row['name'], $category, $location);
                $poi->setExternalId($row['id']);
                $poi->setAddress($row['city']);
                $this->entityManager->persist($poi);
            } else {
                $poi->setName($row['name']);
                $poi->setLocation($location);
                $poi->setAddress($row['city']);
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

    private function findOrCreateCategory(): PoiCategory
    {
        $category = $this->poiCategoryRepository->findOneBy(['code' => self::CATEGORY_CODE]);
        if (null !== $category) {
            return $category;
        }

        $category = new PoiCategory(self::CATEGORY_CODE, 'Association de jeux de société');
        $this->entityManager->persist($category);
        $this->entityManager->flush();

        return $category;
    }
}
