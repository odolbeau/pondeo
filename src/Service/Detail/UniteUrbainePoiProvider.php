<?php

declare(strict_types=1);

namespace App\Service\Detail;

use App\Entity\PoiCategory;
use App\Entity\UniteUrbaine;
use App\Repository\PoiCategoryRepository;
use App\Repository\PoiRepository;
use App\Service\Import\StationImporter;

/**
 * The POIs of an unité urbaine, all of them, grouped by the root of their
 * category's tree (a category without parent is its own group): the detail page
 * lists them next to a map. Stations are left out: they have their own
 * block, with their access time.
 */
final readonly class UniteUrbainePoiProvider
{
    public function __construct(
        private PoiRepository $poiRepository,
        private PoiCategoryRepository $poiCategoryRepository,
        private PoiDeduplicator $poiDeduplicator,
    ) {
    }

    /**
     * @return list<PoiCategoryDetail>
     */
    public function findCategories(UniteUrbaine $uniteUrbaine): array
    {
        $roots = $this->poiCategoryRepository->findRootsByCode();

        /** @var array<string, array{label: string, categories: list<PoiCategory>}> $byGroup */
        $byGroup = [];
        foreach ($this->poiRepository->findCategories($uniteUrbaine) as $category) {
            if (StationImporter::CATEGORY_CODE === $category->getCode()) {
                continue;
            }

            $group = $roots[$category->getCode()];
            $byGroup[$group['code']]['label'] = $group['label'];
            $byGroup[$group['code']]['categories'][] = $category;
        }

        $details = [];
        foreach ($byGroup as $code => $entry) {
            $deduplicated = $this->poiDeduplicator->deduplicate($this->poiRepository->findByCategories($uniteUrbaine, $entry['categories']));
            $details[] = new PoiCategoryDetail($code, $entry['label'], $deduplicated->pois, $deduplicated->otherCategoryLabels);
        }

        usort($details, static fn (PoiCategoryDetail $a, PoiCategoryDetail $b): int => $a->label <=> $b->label);

        return $details;
    }
}
