<?php

declare(strict_types=1);

namespace App\Service\Detail;

/**
 * A distribution over brackets (e.g. households by bracket of income), drawn
 * as columns: the unité urbaine's share of each bracket, against the median
 * of every unité urbaine. Built from the criteria that give each share;
 * coordinates are in the SVG's own units.
 */
final readonly class BracketDistribution
{
    public const int WIDTH = 420;

    private const int LEFT = 34;

    private const int RIGHT = 8;

    private const int TOP = 22;

    private const int PLOT_HEIGHT = 150;

    private const int LABEL_HEIGHT = 20;

    private const int TARGET_TICKS = 4;

    private const float BAR_SHARE_OF_SLOT = 0.5;

    private const int MEDIAN_MARGIN = 6;

    /**
     * @param list<array{label: string, fullLabel: string, value: float, median: ?float, rank: ?int, total: ?int, barX: float, barWidth: float, barY: float, barHeight: float, medianY: ?float, medianX1: float, medianX2: float, labelX: float, valueY: float}> $bars
     * @param list<array{value: int, y: float}>                                                                                                                                                                                                                  $ticks from the first graduation after zero
     */
    private function __construct(
        public string $title,
        public int $period,
        public ?string $unit,
        public int $height,
        public int $plotBottom,
        public int $plotRight,
        public array $bars,
        public array $ticks,
    ) {
    }

    /**
     * @param list<array{shortLabel: string, criterion: CriterionDetail}> $brackets in display order
     */
    public static function fromCriteria(string $title, array $brackets): ?self
    {
        if ([] === $brackets) {
            return null;
        }

        $largest = 1.0;
        foreach ($brackets as ['criterion' => $criterion]) {
            $largest = max($largest, (float) $criterion->value, $criterion->median ?? 0.0);
        }

        $step = AxisScale::niceStep($largest, self::TARGET_TICKS);
        $axisMax = $step * (int) ceil($largest / $step);
        $plotBottom = self::TOP + self::PLOT_HEIGHT;
        $slot = (self::WIDTH - self::LEFT - self::RIGHT) / \count($brackets);
        $barWidth = $slot * self::BAR_SHARE_OF_SLOT;
        $toY = static fn (float $value): float => round($plotBottom - $value / $axisMax * self::PLOT_HEIGHT, 1);

        $bars = [];
        foreach ($brackets as $i => ['shortLabel' => $shortLabel, 'criterion' => $criterion]) {
            $slotX = self::LEFT + $i * $slot;
            $value = (float) $criterion->value;
            $barY = $toY($value);
            $medianY = null === $criterion->median ? null : $toY($criterion->median);

            $bars[] = [
                'label' => $shortLabel,
                'fullLabel' => $criterion->label,
                'value' => $value,
                'median' => $criterion->median,
                'rank' => $criterion->rank,
                'total' => $criterion->total,
                'barX' => round($slotX + ($slot - $barWidth) / 2, 1),
                'barWidth' => round($barWidth, 1),
                'barY' => $barY,
                'barHeight' => round($plotBottom - $barY, 1),
                'medianY' => $medianY,
                'medianX1' => round($slotX + self::MEDIAN_MARGIN, 1),
                'medianX2' => round($slotX + $slot - self::MEDIAN_MARGIN, 1),
                'labelX' => round($slotX + $slot / 2, 1),
                'valueY' => min($barY, $medianY ?? $barY) - 4,
            ];
        }

        $ticks = [];
        for ($tick = $step; $tick <= $axisMax; $tick += $step) {
            $ticks[] = ['value' => $tick, 'y' => $toY((float) $tick)];
        }

        $first = $brackets[0]['criterion'];

        return new self($title, $first->period, $first->unit, $plotBottom + self::LABEL_HEIGHT, $plotBottom, self::WIDTH - self::RIGHT, $bars, $ticks);
    }
}
