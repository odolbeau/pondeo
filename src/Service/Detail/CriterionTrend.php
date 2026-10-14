<?php

declare(strict_types=1);

namespace App\Service\Detail;

/**
 * How a criterion moved between the first and the last period of its series.
 */
final readonly class CriterionTrend
{
    /** Criteria that are already a rate of change: the change of a rate says nothing. */
    public const array NO_TREND_CODES = ['population_croissance_annuelle_moyenne'];

    /** Below this, a change is "stable": 0.5 % of the value, or 0.1 point for a share. */
    private const float RELATIVE_THRESHOLD = 0.5;

    private const float POINTS_THRESHOLD = 0.1;

    /**
     * @param bool  $inPoints  whether $change is a difference in percentage points rather than a relative change in %
     * @param ?bool $favorable null when the change is too small to call a direction
     */
    public function __construct(
        public int $since,
        public float $change,
        public bool $inPoints,
        public ?bool $favorable,
    ) {
    }

    public function isRising(): bool
    {
        return $this->change > 0;
    }

    public function isStable(): bool
    {
        return null === $this->favorable;
    }

    /**
     * Null when there is no change to measure: fewer than two periods, or a relative change from zero.
     *
     * @param array<int, int|float> $series        values by period, oldest first
     * @param bool                  $lowerIsBetter whether a fall is the favourable direction, as set by the criterion's rank order
     */
    public static function fromSeries(array $series, ?string $unit, bool $lowerIsBetter): ?self
    {
        if (\count($series) < 2) {
            return null;
        }

        $since = array_key_first($series);
        $first = $series[$since];
        $last = end($series);

        // A share is compared in points: a relative change of a percentage is hard to read.
        $inPoints = '%' === $unit;
        if (!$inPoints && 0.0 === (float) $first) {
            return null;
        }

        $change = $inPoints ? $last - $first : ($last - $first) / abs($first) * 100;
        $threshold = $inPoints ? self::POINTS_THRESHOLD : self::RELATIVE_THRESHOLD;

        return new self(
            $since,
            (float) $change,
            $inPoints,
            abs($change) < $threshold ? null : ($change < 0) === $lowerIsBetter,
        );
    }
}
