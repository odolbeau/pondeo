<?php

declare(strict_types=1);

namespace App\Service\Detail;

use App\Entity\Poi;

/** A group of POI categories, or a category on its own: what the detail page lists under one heading. */
final readonly class PoiCategoryDetail
{
    /**
     * @param list<Poi>                   $pois
     * @param array<string, list<string>> $otherCategoryLabels by POI id: the categories of the POIs merged into it (same name, same place)
     */
    public function __construct(
        public string $code,
        public string $label,
        public array $pois,
        public array $otherCategoryLabels = [],
    ) {
    }
}
