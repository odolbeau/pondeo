<?php

declare(strict_types=1);

namespace App\Service\Detail;

/**
 * Population of an unité urbaine by sex and age bracket, laid out for an SVG
 * pyramid: men on the left of a central column of age labels, women on the
 * right, brackets from the oldest (top) to the youngest, and a graduated axis
 * in inhabitants. Coordinates are in the SVG's own units.
 */
final readonly class AgePyramid
{
    public const int WIDTH = 420;

    /** Width of the brackets the population is stored in; the pyramid can group several of them. */
    public const int STORED_BRACKET_YEARS = 5;

    /** Height of a row of one stored bracket: wider brackets get proportionally taller rows, so the chart keeps its height. */
    private const int ROW_HEIGHT = 16;

    private const int LABEL_WIDTH = 40;

    private const int TOP = 8;

    private const int AXIS_HEIGHT = 22;

    private const int TARGET_TICKS = 3;

    /**
     * @param list<array{label: string, women: int, men: int, share: float, y: int, womenX: float, womenWidth: float, menX: float, menWidth: float}> $brackets oldest first
     * @param list<array{value: int, menX: float, womenX: float}>                                                                                    $ticks    from the first graduation after zero
     */
    private function __construct(
        public int $year,
        public int $bracketYears,
        public int $total,
        public int $menTotal,
        public int $womenTotal,
        public float $menShare,
        public float $womenShare,
        public int $height,
        public int $rowHeight,
        public int $womenStart,
        public int $labelX,
        public int $plotBottom,
        public array $brackets,
        public array $ticks,
    ) {
    }

    /**
     * @param array{year: int, women: list<int>, men: list<int>} $stored
     * @param int                                                $bracketYears width of the brackets drawn, a multiple of the stored ones (5 or 10)
     */
    public static function fromStored(array $stored, int $bracketYears = self::STORED_BRACKET_YEARS): self
    {
        $group = max(1, intdiv($bracketYears, self::STORED_BRACKET_YEARS));
        $rowHeight = self::ROW_HEIGHT * $group;

        $women = array_map(array_sum(...), array_chunk($stored['women'], $group));
        $men = array_map(array_sum(...), array_chunk($stored['men'], $group));
        $largest = max(1, ...$women, ...$men);

        $half = (self::WIDTH - self::LABEL_WIDTH) / 2.0;
        $womenStart = $half + self::LABEL_WIDTH;

        $step = self::niceStep((float) $largest);
        $axisMax = $step * (int) ceil($largest / $step);
        $perUnit = $half / $axisMax;

        $total = array_sum($stored['men']) + array_sum($stored['women']);
        $last = \count($women) - 1;

        $brackets = [];
        foreach (array_reverse(array_keys($women)) as $row => $i) {
            $start = $i * $group * self::STORED_BRACKET_YEARS;
            $menWidth = round(($men[$i] ?? 0) * $perUnit, 1);
            $brackets[] = [
                'label' => $i === $last ? \sprintf('%d et +', $start) : \sprintf('%d-%d', $start, $start + $bracketYears - 1),
                'women' => $women[$i],
                'men' => $men[$i] ?? 0,
                'share' => $total > 0 ? round(($women[$i] + ($men[$i] ?? 0)) / $total * 100, 1) : 0.0,
                'y' => self::TOP + $row * $rowHeight,
                'womenX' => $womenStart,
                'womenWidth' => round($women[$i] * $perUnit, 1),
                'menX' => round($half - $menWidth, 1),
                'menWidth' => $menWidth,
            ];
        }

        $ticks = [];
        for ($value = $step; $value <= $axisMax; $value += $step) {
            $ticks[] = ['value' => $value, 'menX' => round($half - $value * $perUnit, 1), 'womenX' => round($womenStart + $value * $perUnit, 1)];
        }

        $menTotal = array_sum($stored['men']);
        $womenTotal = array_sum($stored['women']);
        $plotBottom = self::TOP + \count($women) * $rowHeight;

        return new self(
            $stored['year'],
            $bracketYears,
            $total,
            $menTotal,
            $womenTotal,
            $total > 0 ? round($menTotal / $total * 100, 1) : 0.0,
            $total > 0 ? round($womenTotal / $total * 100, 1) : 0.0,
            $plotBottom + self::AXIS_HEIGHT,
            $rowHeight,
            (int) $womenStart,
            (int) ($half + self::LABEL_WIDTH / 2),
            $plotBottom,
            $brackets,
            $ticks,
        );
    }

    /**
     * A round graduation (1, 2, 5, 10, 20, 50… times a power of ten) giving about three marks on the axis.
     */
    private static function niceStep(float $largest): int
    {
        $raw = max(1.0, $largest / self::TARGET_TICKS);
        $magnitude = 10 ** floor(log10($raw));

        foreach ([1, 2, 5, 10] as $multiple) {
            if ($raw <= $multiple * $magnitude) {
                return max(1, (int) ($multiple * $magnitude));
            }
        }

        return max(1, (int) (10 * $magnitude));
    }
}
