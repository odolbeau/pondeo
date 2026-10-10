<?php

declare(strict_types=1);

namespace App\Service\Detail;

use App\Entity\Poi;

final readonly class PoiCategoryDetail
{
    /**
     * @param list<Poi> $pois the first few POIs of the category; $total counts them all
     */
    public function __construct(
        public string $label,
        public int $total,
        public array $pois,
    ) {
    }
}
