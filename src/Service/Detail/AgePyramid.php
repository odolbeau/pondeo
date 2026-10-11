<?php

declare(strict_types=1);

namespace App\Service\Detail;

/**
 * Population of an unité urbaine by sex and five-year age bracket, laid out
 * for an SVG pyramid: men on the left of a central column of age labels,
 * women on the right, brackets from the oldest (top) to the youngest, and a
 * graduated axis in inhabitants. Coordinates are in the SVG's own units.
 */
final readonly class AgePyramid
{
    public const int WIDTH = 600;

    public const int ROW_HEIGHT = 16;

    private const int BRACKET_SIZE = 5;

    private const int LABEL_WIDTH = 44;

    private const int TOP = 8;

    private const int AXIS_HEIGHT = 22;

    private const int TARGET_TICKS = 3;

    /**
     * @param list<array{label: string, women: int, men: int, share: float, y: int, womenX: float, womenWidth: float, menX: float, menWidth: float}> $brackets oldest first
     * @param list<array{value: int, menX: float, womenX: float}>                                                                                    $ticks    from the first graduation after zero
     */
    private function __construct(
        public int $year,
        public int $total,
        public int $menTotal,
        public int $womenTotal,
        public float $menShare,
        public float $womenShare,
        public int $height,
        public int $womenStart,
        public int $labelX,
        public int $plotBottom,
        public array $brackets,
        public array $ticks,
    ) {
    }

    /**
     * @param array{year: int, women: list<int>, men: list<int>} $stored
     */
    public static function fromStored(array $stored): self
    {
        $half = (self::WIDTH - self::LABEL_WIDTH) / 2.0;
        $menBaseline = $half;
        $womenStart = $half + self::LABEL_WIDTH;

        $step = self::niceStep((float) max(1, ...$stored['women'], ...$stored['men']));
        $axisMax = $step * max(1, (int) ceil(max(1, ...$stored['women'], ...$stored['men']) / $step));
        $perUnit = $half / $axisMax;

        $last = \count($stored['women']) - 1;
        $rows = [];
        foreach ($stored['women'] as $i => $women) {
            $men = $stored['men'][$i] ?? 0;
            $rows[] = [
                'label' => $i === $last ? \sprintf('%d et +', $i * self::BRACKET_SIZE) : \sprintf('%d-%d', $i * self::BRACKET_SIZE, $i * self::BRACKET_SIZE + self::BRACKET_SIZE - 1),
                'women' => $women,
                'men' => $men,
            ];
        }

        $total = array_sum($stored['men']) + array_sum($stored['women']);

        $brackets = [];
        foreach (array_reverse($rows) as $row => $bracket) {
            $menWidth = round($bracket['men'] * $perUnit, 1);
            $brackets[] = $bracket + [
                'share' => $total > 0 ? round(($bracket['men'] + $bracket['women']) / $total * 100, 1) : 0.0,
                'y' => self::TOP + $row * self::ROW_HEIGHT,
                'womenX' => $womenStart,
                'womenWidth' => round($bracket['women'] * $perUnit, 1),
                'menX' => round($menBaseline - $menWidth, 1),
                'menWidth' => $menWidth,
            ];
        }

        $ticks = [];
        for ($value = $step; $value <= $axisMax; $value += $step) {
            $ticks[] = ['value' => $value, 'menX' => round($menBaseline - $value * $perUnit, 1), 'womenX' => round($womenStart + $value * $perUnit, 1)];
        }

        $men = array_sum($stored['men']);
        $women = array_sum($stored['women']);
        $plotBottom = self::TOP + \count($rows) * self::ROW_HEIGHT;

        return new self(
            $stored['year'],
            $total,
            $men,
            $women,
            $total > 0 ? round($men / $total * 100, 1) : 0.0,
            $total > 0 ? round($women / $total * 100, 1) : 0.0,
            $plotBottom + self::AXIS_HEIGHT,
            (int) $womenStart,
            (int) ($menBaseline + self::LABEL_WIDTH / 2),
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
