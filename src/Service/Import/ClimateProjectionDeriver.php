<?php

declare(strict_types=1);

namespace App\Service\Import;

/**
 * Turns the daily temperatures of a few climate models into the figures of
 * `docs/criteria-climate-projections.md`. Every figure is computed per model
 * first, then averaged over the models: a model is never compared to another's
 * baseline.
 */
final readonly class ClimateProjectionDeriver
{
    public const float HOT_DAY_THRESHOLD = 35.0;

    public const float TROPICAL_NIGHT_THRESHOLD = 20.0;

    // A model with fewer valid days in a year (a missing year, a truncated one) is left out of that year.
    private const int MIN_DAYS_PER_YEAR = 300;

    /**
     * @param array<string, array{time: list<string>, mean: list<float|null>}>                                               $baseline daily series of the reference period, by model
     * @param array<string, array{time: list<string>, max: list<float|null>, min: list<float|null>, mean: list<float|null>}> $future   daily series of the projection window, by model
     *
     * @return array{hotDays: float, tropicalNights: float, warming: float}|null null when no model has data for every figure
     */
    public function derive(array $baseline, array $future): ?array
    {
        $hotDays = [];
        $tropicalNights = [];
        $warming = [];

        foreach ($future as $model => $series) {
            $hot = $this->meanOfYearlyCounts($series['time'], $series['max'], static fn (float $t): bool => $t > self::HOT_DAY_THRESHOLD);
            $tropical = $this->meanOfYearlyCounts($series['time'], $series['min'], static fn (float $t): bool => $t > self::TROPICAL_NIGHT_THRESHOLD);
            $futureMean = $this->mean($series['mean']);
            $baselineMean = isset($baseline[$model]) ? $this->mean($baseline[$model]['mean']) : null;
            if (\in_array(null, [$hot, $tropical, $futureMean, $baselineMean], true)) {
                continue;
            }

            $hotDays[] = $hot;
            $tropicalNights[] = $tropical;
            $warming[] = $futureMean - $baselineMean;
        }

        if ([] === $hotDays) {
            return null;
        }

        return [
            'hotDays' => array_sum($hotDays) / \count($hotDays),
            'tropicalNights' => array_sum($tropicalNights) / \count($tropicalNights),
            'warming' => array_sum($warming) / \count($warming),
        ];
    }

    /**
     * @param list<string>          $times  ISO dates
     * @param list<float|null>      $values
     * @param \Closure(float): bool $counts
     */
    private function meanOfYearlyCounts(array $times, array $values, \Closure $counts): ?float
    {
        /** @var array<string, array{days: int, count: int}> $years */
        $years = [];
        foreach ($times as $i => $time) {
            $value = $values[$i] ?? null;
            if (null === $value) {
                continue;
            }

            $year = substr($time, 0, 4);
            $years[$year] ??= ['days' => 0, 'count' => 0];
            ++$years[$year]['days'];
            if ($counts($value)) {
                ++$years[$year]['count'];
            }
        }

        $yearly = [];
        foreach ($years as $year) {
            if ($year['days'] >= self::MIN_DAYS_PER_YEAR) {
                $yearly[] = $year['count'];
            }
        }

        return [] === $yearly ? null : array_sum($yearly) / \count($yearly);
    }

    /**
     * @param list<float|null> $values
     */
    private function mean(array $values): ?float
    {
        $values = array_filter($values, static fn (?float $v): bool => null !== $v);

        return [] === $values ? null : array_sum($values) / \count($values);
    }
}
