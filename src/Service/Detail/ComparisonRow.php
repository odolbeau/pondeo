<?php

declare(strict_types=1);

namespace App\Service\Detail;

/** One criterion across the compared unités urbaines. */
final readonly class ComparisonRow
{
    /**
     * @param list<?CriterionDetail> $cells one per compared unité urbaine, in order; null when it has no value
     * @param list<int>              $best  indexes of the cells holding the best rank
     */
    public function __construct(
        public string $code,
        public string $label,
        public ?string $unit,
        public array $cells,
        public array $best,
    ) {
    }
}
