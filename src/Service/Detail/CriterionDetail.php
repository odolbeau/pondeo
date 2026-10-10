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

    /**
     * Points of an SVG polyline drawing the series, or null when there is
     * nothing to draw (fewer than two periods).
     */
    public function sparklinePoints(int $width, int $height): ?string
    {
        if (\count($this->series) < 2) {
            return null;
        }

        $min = min($this->series);
        $range = max($this->series) - $min;
        $lastIndex = \count($this->series) - 1;

        $points = [];
        foreach (array_values($this->series) as $index => $value) {
            $x = $index / $lastIndex * $width;
            // A flat series is drawn as a mid-height line rather than dividing by zero.
            $y = 0 === $range ? $height / 2 : $height - ($value - $min) / $range * $height;
            $points[] = \sprintf('%.1f,%.1f', $x, $y);
        }

        return implode(' ', $points);
    }
}
