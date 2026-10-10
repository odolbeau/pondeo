<?php

declare(strict_types=1);

namespace App\Service\Detail;

/**
 * One criterion as shown on an unité urbaine's detail page: its latest value
 * plus, for numeric criteria, the comparison with every other unité urbaine
 * that has a value for the same period.
 */
final readonly class CriterionDetail
{
    /**
     * @param array<int, int|float> $series numeric values by period, oldest first; empty for text/boolean criteria
     * @param ?int                  $rank   1 = highest value; null when the criterion isn't numeric
     */
    public function __construct(
        public string $code,
        public string $label,
        public ?string $unit,
        public int|float|string|bool $value,
        public int $period,
        public array $series,
        public ?int $rank,
        public ?int $total,
        public ?float $median,
    ) {
    }
}
