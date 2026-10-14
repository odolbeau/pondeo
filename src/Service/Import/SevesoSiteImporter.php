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
 * Reads the vendored `resources/geo/sites-seveso.csv` (see
 * SevesoSiteSourceDownloader) and upserts the sites as POIs, one category per
 * Seveso level, matched on the source's site identifier. Idempotent:
 * re-running updates in place, never duplicates, moves a site whose level
 * changed to its new category, and drops the sites the source no longer
 * lists. Needs the zones imported first, to attach each site to its unité
 * urbaine.
 */
final readonly class SevesoSiteImporter
{
    public const string LEVEL_HIGH = 'high';

    public const string LEVEL_LOW = 'low';

    private const array CATEGORIES = [
        self::LEVEL_HIGH => ['seveso_high', 'Site Seveso seuil haut'],
        self::LEVEL_LOW => ['seveso_low', 'Site Seveso seuil bas'],
    ];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private PoiCategoryRepository $poiCategoryRepository,
        private PoiRepository $poiRepository,
        private PoiUniteUrbaineResolver $uniteUrbaineResolver,
        private CsvReader $csvReader,
    ) {
    }

    /**
     * @return int number of sites imported
     */
    public function import(string $csvPath): int
    {
        $categories = $this->findOrCreateCategories();

        /** @var array<string, Poi> $stale */
        $stale = [];
        foreach ($categories as $category) {
            foreach ($this->poiRepository->findByCategoryWithUniteUrbaine($category) as $poi) {
                $stale[(string) $poi->getExternalId()] = $poi;
            }
        }

        $count = 0;
        foreach ($this->csvReader->read($csvPath) as $row) {
            $category = $categories[$row['level']] ?? null;
            if (null === $category) {
                throw new \LogicException(\sprintf('Unknown Seveso level "%s".', $row['level']));
            }

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

            $poi->setAddress('' === $row['address'] ? null : $row['address']);
            $poi->setAttributes('' === $row['activity'] ? null : ['activity' => $row['activity']]);

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

    /**
     * @return array<string, PoiCategory> by Seveso level
     */
    private function findOrCreateCategories(): array
    {
        $categories = [];
        foreach (self::CATEGORIES as $level => [$code, $label]) {
            $category = $this->poiCategoryRepository->findOneBy(['code' => $code]);
            if (null === $category) {
                $category = new PoiCategory($code, $label);
                $this->entityManager->persist($category);
            }

            $categories[$level] = $category;
        }

        $this->entityManager->flush();

        return $categories;
    }
}
