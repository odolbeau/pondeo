<?php

declare(strict_types=1);

namespace App\Service\Filter;

use App\Enum\AttractionCategory;

/**
 * What the filters of the map, the rankings and the personal ranking keep. A
 * null bound, an empty list or a false flag means the filter is off.
 */
final readonly class UniteUrbaineFilter
{
    /**
     * @param list<AttractionCategory> $excludedAttractionCategories
     * @param array<string, int>       $trainLimits                  maximum minutes by JourneyDestination value
     */
    public function __construct(
        public ?int $populationMin = null,
        public ?int $populationMax = null,
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

    /**
     * Whether the filter keeps every unité urbaine. The price type and the
     * transfers maximum only qualify other filters: alone, they keep everything.
     */
    public function isEmpty(): bool
    {
        return null === $this->populationMin
            && null === $this->populationMax
            && [] === $this->excludedAttractionCategories
            && null === $this->commerceDensityMin
            && null === $this->restaurantDensityMin
            && null === $this->nuclearExclusionKm
            && null === $this->priceMax
            && !$this->withStation
            && !$this->withBoardGameAssociation
            && [] === $this->trainLimits;
    }
}
