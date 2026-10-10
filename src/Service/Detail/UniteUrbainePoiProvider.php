<?php

declare(strict_types=1);

namespace App\Service\Detail;

use App\Entity\UniteUrbaine;
use App\Repository\PoiRepository;

/**
 * The POIs of an unité urbaine by category. Only a few of each category are
 * loaded: the detail page shows the count and a sample, not the full list.
 */
final readonly class UniteUrbainePoiProvider
{
    public const int SAMPLE_SIZE = 10;

    public function __construct(private PoiRepository $poiRepository)
    {
    }

    /**
     * @return list<PoiCategoryDetail>
     */
    public function findCategories(UniteUrbaine $uniteUrbaine): array
    {
        $categories = [];
        foreach ($this->poiRepository->countByCategory($uniteUrbaine) as ['category' => $category, 'total' => $total]) {
            $categories[] = new PoiCategoryDetail(
                $category->getLabel(),
                $total,
                $this->poiRepository->findByCategory($uniteUrbaine, $category, self::SAMPLE_SIZE),
            );
        }

        return $categories;
    }
}
