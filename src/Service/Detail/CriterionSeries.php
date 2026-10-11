<?php

declare(strict_types=1);

namespace App\Service\Detail;

/**
 * The evolution of one numeric criterion for one unité urbaine, next to the
 * median of all unités urbaines for each period, for the detail page's chart.
 * The three lists are aligned on `periods`.
 */
final readonly class CriterionSeries
{
    /**
     * @param list<int>         $periods oldest first
     * @param list<?float>      $values  the unité urbaine's value; null for a period it has none
     * @param list<?float>|null $medians median of every unité urbaine with a value; null when the criterion isn't comparable
     */
    public function __construct(
        public string $label,
        public ?string $unit,
        public array $periods,
        public array $values,
        public ?array $medians,
    ) {
    }
}
