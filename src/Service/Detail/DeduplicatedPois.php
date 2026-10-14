<?php

declare(strict_types=1);

namespace App\Service\Detail;

use App\Entity\Poi;

final readonly class DeduplicatedPois
{
    /**
     * @param list<Poi>                   $pois                the POIs kept, one per place
     * @param array<string, list<string>> $otherCategoryLabels by kept POI id: the categories of the POIs merged into it
     */
    public function __construct(
        public array $pois,
        public array $otherCategoryLabels,
    ) {
    }
}
