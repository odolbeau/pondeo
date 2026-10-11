<?php

declare(strict_types=1);

namespace App\Service\Detail;

/**
 * A distribution over brackets (e.g. households by bracket of income), drawn
 * as columns or rows: the unité urbaine's share of each bracket, against the
 * median of every unité urbaine. Built from the criteria that give each
 * share; coordinates are in the SVG's own units, so the template draws it
 * the same way whatever the layout.
 *
 * @phpstan-type Point array{x: float, y: float}
 * @phpstan-type Segment array{x1: float, y1: float, x2: float, y2: float}
 * @phpstan-type Text array{x: float, y: float, anchor: string}
 */
final readonly class BracketDistribution
{
    public const int WIDTH = 420;

    private const int AXIS_LABEL_SIZE = 20;

    private const int TARGET_TICKS = 4;

    // Columns: a bracket per column, its label under the plot.
    private const int COLUMN_LEFT = 34;

    private const int COLUMN_RIGHT = 8;

    private const int COLUMN_TOP = 8;

    private const int COLUMN_PLOT_HEIGHT = 150;

    private const float COLUMN_BAR_SHARE_OF_SLOT = 0.5;

    private const int COLUMN_MEDIAN_MARGIN = 6;

    // Rows: a bracket per row, its label to the left of the plot.
    private const int ROW_LABEL_WIDTH = 140;

    private const int ROW_RIGHT = 12;

    private const int ROW_TOP = 4;

    private const int ROW_HEIGHT = 24;

    private const int ROW_BAR_HEIGHT = 14;

    /**
     * @param list<array{label: string, fullLabel: string, value: float, median: ?float, rank: ?int, total: ?int, bar: array{x: float, y: float, width: float, height: float}, median_segment: ?Segment, label_text: Text, hit: array{x: float, y: float, width: float, height: float}}> $bars
     * @param list<array{value: int, grid: Segment, label_text: Text}>                                                                                                                                                                                                                   $ticks from the first graduation after zero
     * @param Segment                                                                                                                                                                                                                                                                    $axis
     */
    private function __construct(
        public string $title,
        public int $period,
        public ?string $unit,
        public int $height,
        public array $bars,
        public array $ticks,
        public array $axis,
    ) {
    }

    /**
     * @param list<array{shortLabel: string, criterion: CriterionDetail}> $brackets in display order
     */
    public static function fromCriteria(string $title, array $brackets, BracketLayout $layout = BracketLayout::Columns): ?self
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
        $first = $brackets[0]['criterion'];

        return BracketLayout::Columns === $layout
            ? self::columns($title, $brackets, $step, $axisMax, $first)
            : self::rows($title, $brackets, $step, $axisMax, $first);
    }

    /**
     * @param list<array{shortLabel: string, criterion: CriterionDetail}> $brackets
     */
    private static function columns(string $title, array $brackets, int $step, int $axisMax, CriterionDetail $first): self
    {
        $plotBottom = self::COLUMN_TOP + self::COLUMN_PLOT_HEIGHT;
        $plotRight = self::WIDTH - self::COLUMN_RIGHT;
        $slot = ($plotRight - self::COLUMN_LEFT) / \count($brackets);
        $barWidth = $slot * self::COLUMN_BAR_SHARE_OF_SLOT;
        $toY = static fn (float $value): float => round($plotBottom - $value / $axisMax * self::COLUMN_PLOT_HEIGHT, 1);

        $bars = [];
        foreach ($brackets as $i => ['shortLabel' => $shortLabel, 'criterion' => $criterion]) {
            $slotX = self::COLUMN_LEFT + $i * $slot;
            $center = round($slotX + $slot / 2, 1);
            $barY = $toY((float) $criterion->value);
            $medianY = null === $criterion->median ? null : $toY($criterion->median);

            $bars[] = self::bar(
                $shortLabel,
                $criterion,
                ['x' => round($slotX + ($slot - $barWidth) / 2, 1), 'y' => $barY, 'width' => round($barWidth, 1), 'height' => round($plotBottom - $barY, 1)],
                null === $medianY ? null : ['x1' => round($slotX + self::COLUMN_MEDIAN_MARGIN, 1), 'y1' => $medianY, 'x2' => round($slotX + $slot - self::COLUMN_MEDIAN_MARGIN, 1), 'y2' => $medianY],
                ['x' => $center, 'y' => (float) ($plotBottom + 15), 'anchor' => 'middle'],
                ['x' => round($slotX, 1), 'y' => (float) self::COLUMN_TOP, 'width' => round($slot, 1), 'height' => (float) (self::COLUMN_PLOT_HEIGHT + self::AXIS_LABEL_SIZE)],
            );
        }

        $ticks = [];
        for ($tick = $step; $tick <= $axisMax; $tick += $step) {
            $y = $toY((float) $tick);
            $ticks[] = ['value' => $tick, 'grid' => ['x1' => 30.0, 'y1' => $y, 'x2' => (float) $plotRight, 'y2' => $y], 'label_text' => ['x' => 26.0, 'y' => $y + 3, 'anchor' => 'end']];
        }

        return new self($title, $first->period, $first->unit, $plotBottom + self::AXIS_LABEL_SIZE, $bars, $ticks, ['x1' => 30.0, 'y1' => (float) $plotBottom, 'x2' => (float) $plotRight, 'y2' => (float) $plotBottom]);
    }

    /**
     * @param list<array{shortLabel: string, criterion: CriterionDetail}> $brackets
     */
    private static function rows(string $title, array $brackets, int $step, int $axisMax, CriterionDetail $first): self
    {
        $plotLeft = self::ROW_LABEL_WIDTH + 6;
        $plotRight = self::WIDTH - self::ROW_RIGHT;
        $plotBottom = self::ROW_TOP + \count($brackets) * self::ROW_HEIGHT;
        $perUnit = ($plotRight - $plotLeft) / $axisMax;
        $toX = static fn (float $value): float => round($plotLeft + $value * $perUnit, 1);

        $bars = [];
        foreach ($brackets as $i => ['shortLabel' => $shortLabel, 'criterion' => $criterion]) {
            $rowTop = self::ROW_TOP + $i * self::ROW_HEIGHT;
            $middle = $rowTop + self::ROW_HEIGHT / 2 + 4;
            $barEnd = $toX((float) $criterion->value);
            $medianX = null === $criterion->median ? null : $toX($criterion->median);

            $bars[] = self::bar(
                $shortLabel,
                $criterion,
                ['x' => (float) $plotLeft, 'y' => (float) ($rowTop + (self::ROW_HEIGHT - self::ROW_BAR_HEIGHT) / 2), 'width' => round($barEnd - $plotLeft, 1), 'height' => (float) self::ROW_BAR_HEIGHT],
                null === $medianX ? null : ['x1' => $medianX, 'y1' => (float) ($rowTop + 2), 'x2' => $medianX, 'y2' => (float) ($rowTop + self::ROW_HEIGHT - 2)],
                ['x' => (float) self::ROW_LABEL_WIDTH, 'y' => $middle, 'anchor' => 'end'],
                ['x' => 0.0, 'y' => (float) $rowTop, 'width' => (float) self::WIDTH, 'height' => (float) self::ROW_HEIGHT],
            );
        }

        $ticks = [];
        for ($tick = $step; $tick <= $axisMax; $tick += $step) {
            $x = $toX((float) $tick);
            $ticks[] = ['value' => $tick, 'grid' => ['x1' => $x, 'y1' => (float) self::ROW_TOP, 'x2' => $x, 'y2' => (float) $plotBottom], 'label_text' => ['x' => $x, 'y' => (float) ($plotBottom + 14), 'anchor' => 'middle']];
        }

        return new self($title, $first->period, $first->unit, $plotBottom + self::AXIS_LABEL_SIZE, $bars, $ticks, ['x1' => (float) $plotLeft, 'y1' => (float) self::ROW_TOP, 'x2' => (float) $plotLeft, 'y2' => (float) $plotBottom]);
    }

    /**
     * @param array{x: float, y: float, width: float, height: float} $rect
     * @param Segment|null                                           $median
     * @param Text                                                   $labelText
     * @param array{x: float, y: float, width: float, height: float} $hit       the area that answers to the pointer
     *
     * @return array{label: string, fullLabel: string, value: float, median: ?float, rank: ?int, total: ?int, bar: array{x: float, y: float, width: float, height: float}, median_segment: ?Segment, label_text: Text, hit: array{x: float, y: float, width: float, height: float}}
     */
    private static function bar(string $shortLabel, CriterionDetail $criterion, array $rect, ?array $median, array $labelText, array $hit): array
    {
        return [
            'label' => $shortLabel,
            'fullLabel' => $criterion->label,
            'value' => (float) $criterion->value,
            'median' => $criterion->median,
            'rank' => $criterion->rank,
            'total' => $criterion->total,
            'bar' => $rect,
            'median_segment' => $median,
            'label_text' => $labelText,
            'hit' => $hit,
        ];
    }
}
