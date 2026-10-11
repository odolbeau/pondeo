<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Enum\AttractionCategory;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Query-string filters for the unité urbaine map data endpoint; a future
 * filter is a new property here. Null population bounds mean "not set" — the
 * controller falls back to the full bounds. The exclusions default to off:
 * every unité urbaine is shown until asked otherwise. The commerce
 * density minimum (shops per 1000 inhabitants) is likewise off when null, as is the exclusion radius (km) around nuclear plants. The price per m² maximum (€) has no minimum counterpart and is off when null.
 */
final readonly class UniteUrbaineMapDataInput
{
    public function __construct(
        #[Assert\PositiveOrZero]
        public ?int $populationMin = null,
        #[Assert\PositiveOrZero]
        public ?int $populationMax = null,
        public bool $excludeCouronne = false,
        public bool $excludePolesSecondaires = false,
        #[Assert\PositiveOrZero]
        public ?float $commerceDensityMin = null,
        #[Assert\PositiveOrZero]
        public ?float $nuclearExclusionKm = null,
        #[Assert\PositiveOrZero]
        public ?float $priceMax = null,
    ) {
    }

    /**
     * @return list<AttractionCategory>
     */
    public function excludedAttractionCategories(): array
    {
        $excluded = [];
        if ($this->excludeCouronne) {
            $excluded[] = AttractionCategory::Couronne;
        }

        if ($this->excludePolesSecondaires) {
            $excluded[] = AttractionCategory::PoleSecondaire;
        }

        return $excluded;
    }
}
