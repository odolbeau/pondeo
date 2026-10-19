<?php

declare(strict_types=1);

namespace App\Service\Map;

use App\Enum\AttractionCategory;

/**
 * What the map filters keep, shared by the map data and the export. A null
 * bound, an empty list or a false flag means the filter is off.
 */
final readonly class UniteUrbaineMapFilter
{
    /**
     * @param list<AttractionCategory> $excludedAttractionCategories
     * @param array<string, int>       $trainLimits                  maximum minutes by JourneyDestination value
     */
    public function __construct(
        public int $populationMin,
        public int $populationMax,
        public array $excludedAttractionCategories = [],
        public ?float $commerceDensityMin = null,
        public ?float $restaurantDensityMin = null,
        public ?float $nuclearExclusionKm = null,
        public ?float $priceMax = null,
        public string $priceCriterionCode = 'prix_m2',
        public bool $withStation = false,
        public bool $withBoardGameAssociation = false,
        public array $trainLimits = [],
        public ?int $trainMaxTransfers = null,
    ) {
    }
}
