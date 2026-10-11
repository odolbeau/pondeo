<?php

declare(strict_types=1);

namespace App\Service\Import;

/**
 * Turns the daily series of a few climate models into the figures of
 * `docs/criteria-climate-projections.md`. Every figure is computed per model
 * first, then combined over the models: a model is never compared to another's
 * baseline.
 */
final readonly class ClimateProjectionDeriver
{
    public const float HOT_DAY_THRESHOLD = 35.0;

    public const float TROPICAL_NIGHT_THRESHOLD = 20.0;

    public const float HEAT_WAVE_THRESHOLD = 30.0;

    public const int HEAT_WAVE_MIN_DAYS = 3;

    // A model with fewer valid days in a year (a missing year, a truncated one) is left out of that year.
    private const int MIN_DAYS_PER_YEAR = 300;

    /**
     * @param array<string, array{time: list<string>, mean: list<float|null>, precipitation: list<float|null>}>                                               $baseline daily series of the reference period, by model
     * @param array<string, array{time: list<string>, max: list<float|null>, min: list<float|null>, mean: list<float|null>, precipitation: list<float|null>}> $future   daily series of the projection window, by model
     *
     * @return array{hotDays: float, tropicalNights: float, warming: float, heatWaves: float, precipitationChange: float|null, uncertainty: float|null}|null null when no model has temperatures for every figure; the precipitation change and the uncertainty (which needs two models) are null when they cannot be computed
     */
    public function derive(array $baseline, array $future): ?array
    {
        $hotDays = [];
        $tropicalNights = [];
        $warming = [];
        $heatWaves = [];
        $precipitationChange = [];

        foreach ($future as $model => $series) {
            $hot = $this->meanOfYearlyCounts($series['time'], $series['max'], static fn (float $t): bool => $t > self::HOT_DAY_THRESHOLD);
            $tropical = $this->meanOfYearlyCounts($series['time'], $series['min'], static fn (float $t): bool => $t > self::TROPICAL_NIGHT_THRESHOLD);
            $waves = $this->meanOfYearlyHeatWaves($series['time'], $series['max']);
            $futureMean = $this->mean($series['mean']);
            $baselineMean = isset($baseline[$model]) ? $this->mean($baseline[$model]['mean']) : null;
            if (\in_array(null, [$hot, $tropical, $waves, $futureMean, $baselineMean], true)) {
                continue;
            }

            $hotDays[] = $hot;
            $tropicalNights[] = $tropical;
            $heatWaves[] = $waves;
            $warming[] = $futureMean - $baselineMean;

            $futureRain = $this->meanOfYearlySums($series['time'], $series['precipitation']);
            $baselineRain = $this->meanOfYearlySums($baseline[$model]['time'], $baseline[$model]['precipitation']);
            if (null !== $futureRain && null !== $baselineRain && $baselineRain > 0.0) {
                $precipitationChange[] = ($futureRain - $baselineRain) / $baselineRain * 100;
            }
        }

        if ([] === $hotDays) {
            return null;
        }

        return [
            'hotDays' => array_sum($hotDays) / \count($hotDays),
            'tropicalNights' => array_sum($tropicalNights) / \count($tropicalNights),
            'warming' => array_sum($warming) / \count($warming),
            'heatWaves' => array_sum($heatWaves) / \count($heatWaves),
            'precipitationChange' => [] === $precipitationChange ? null : array_sum($precipitationChange) / \count($precipitationChange),
            'uncertainty' => \count($warming) < 2 ? null : max($warming) - min($warming),
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

        return $this->meanOfCompleteYears($years, 'count');
    }

    /**
     * Episodes of at least HEAT_WAVE_MIN_DAYS consecutive days above the threshold; a missing
     * day breaks a run, and a run never spans two years.
     *
     * @param list<string>     $times
     * @param list<float|null> $values
     */
    private function meanOfYearlyHeatWaves(array $times, array $values): ?float
    {
        /** @var array<string, array{days: int, waves: int}> $years */
        $years = [];
        $run = 0;
        $runYear = null;
        foreach ($times as $i => $time) {
            $value = $values[$i] ?? null;
            $year = substr($time, 0, 4);
            if ($year !== $runYear) {
                $run = 0;
                $runYear = $year;
            }

            if (null === $value) {
                $run = 0;

                continue;
            }

            $years[$year] ??= ['days' => 0, 'waves' => 0];
            ++$years[$year]['days'];

            if ($value > self::HEAT_WAVE_THRESHOLD) {
                ++$run;
                if (self::HEAT_WAVE_MIN_DAYS === $run) {
                    ++$years[$year]['waves'];
                }
            } else {
                $run = 0;
            }
        }

        return $this->meanOfCompleteYears($years, 'waves');
    }

    /**
     * @param list<string>     $times
     * @param list<float|null> $values
     */
    private function meanOfYearlySums(array $times, array $values): ?float
    {
        /** @var array<string, array{days: int, sum: float}> $years */
        $years = [];
        foreach ($times as $i => $time) {
            $value = $values[$i] ?? null;
            if (null === $value) {
                continue;
            }

            $year = substr($time, 0, 4);
            $years[$year] ??= ['days' => 0, 'sum' => 0.0];
            ++$years[$year]['days'];
            $years[$year]['sum'] += $value;
        }

        return $this->meanOfCompleteYears($years, 'sum');
    }

    /**
     * @param array<string, array{days: int}&array<string, int|float>> $years
     */
    private function meanOfCompleteYears(array $years, string $key): ?float
    {
        $yearly = [];
        foreach ($years as $year) {
            if ($year['days'] >= self::MIN_DAYS_PER_YEAR) {
                $yearly[] = $year[$key];
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
