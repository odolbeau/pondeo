<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Service\Import\ClimateProjectionDeriver;
use PHPUnit\Framework\TestCase;

final class ClimateProjectionDeriverTest extends TestCase
{
    public function testDerivesYearlyCountsWindowMeansAndTheWarmingPerModel(): void
    {
        // Two years of 310 days: 3 hot days and 2 tropical nights in the first, 1 and 0 in the second.
        [$time, $max, $min] = $this->twoYears();
        foreach ([0, 1, 2] as $i) {
            $max[$i] = 36.0;
        }

        $max[310] = 35.1;
        $max[311] = 35.0; // not strictly above the threshold
        $min[5] = 21.0;
        $min[6] = 20.5;
        $min[7] = 20.0;
        $mean = array_fill(0, 620, 15.0);
        $max = array_values($max);
        $min = array_values($min);

        $future = [
            'A' => ['time' => $time, 'max' => $max, 'min' => $min, 'mean' => $mean, 'precipitation' => array_fill(0, 620, 2.0)],
            // The second model has hot days only in the first year, and a hotter baseline.
            'B' => ['time' => $time, 'max' => [...array_fill(0, 4, 40.0), ...array_fill(0, 616, 10.0)], 'min' => array_fill(0, 620, 5.0), 'mean' => array_fill(0, 620, 17.0), 'precipitation' => array_fill(0, 620, 3.0)],
        ];
        $baseline = [
            'A' => ['time' => ['1991-01-01', '1991-01-02'], 'mean' => [12.0, 14.0], 'precipitation' => [1.0, 1.0]],
            'B' => ['time' => ['1991-01-01', '1991-01-02'], 'mean' => [10.0, null], 'precipitation' => [3.0, null]],
        ];

        $result = new ClimateProjectionDeriver()->derive($baseline, $future);

        self::assertNotNull($result);
        // A: (3 + 1) / 2 = 2; B: (4 + 0) / 2 = 2.
        self::assertEqualsWithDelta(2.0, $result['hotDays'], 1e-9);
        // A: (2 + 0) / 2 = 1; B: 0.
        self::assertEqualsWithDelta(0.5, $result['tropicalNights'], 1e-9);
        // A: 15 - 13 = 2; B: 17 - 10 = 7.
        self::assertEqualsWithDelta(4.5, $result['warming'], 1e-9);
        // The models disagree by 7 - 2 degrees.
        self::assertEqualsWithDelta(5.0, $result['uncertainty'], 1e-9);
        // The baseline only has 2 days, which makes a year of 2 days (not 300): rain is then unknown for the baseline.
        self::assertNull($result['precipitationChange']);
    }

    public function testCountsHeatWavesAsRunsOfThreeDaysAboveThirtyWithinAYear(): void
    {
        [$time, , $min] = $this->twoYears();
        $max = array_fill(0, 620, 20.0);
        // Year 1: a run of 3 (one wave), a run of 2 (none), a run of 7 (one wave, not seven).
        foreach ([10, 11, 12, 20, 21, 30, 31, 32, 33, 34, 35, 36] as $i) {
            $max[$i] = 31.0;
        }

        // The last 2 days of year 1 and the first of year 2 do not make a wave.
        $max[308] = $max[309] = $max[310] = 31.0;
        // A missing day breaks a run.
        $max[400] = $max[401] = 31.0;
        $max[402] = null;
        $max[403] = 31.0;
        // Year 2: one wave.
        $max[500] = $max[501] = $max[502] = 30.5;

        $series = ['time' => $time, 'max' => $max, 'min' => $min, 'mean' => array_fill(0, 620, 15.0), 'precipitation' => array_fill(0, 620, 1.0)];
        $result = new ClimateProjectionDeriver()->derive(
            ['A' => ['time' => $time, 'mean' => array_fill(0, 620, 14.0), 'precipitation' => array_fill(0, 620, 1.0)]],
            ['A' => $series],
        );

        self::assertNotNull($result);
        self::assertEqualsWithDelta(1.5, $result['heatWaves'], 1e-9);
        // A single model cannot disagree with itself: no spread rather than a misleading 0.
        self::assertNull($result['uncertainty']);
    }

    public function testPrecipitationChangeIsThePercentageChangeOfTheYearlyTotalAveragedOverTheModels(): void
    {
        [$time, $max, $min] = $this->twoYears();
        $model = static fn (float $baselineRain, float $futureRain): array => [
            'baseline' => ['time' => $time, 'mean' => array_fill(0, 620, 10.0), 'precipitation' => array_fill(0, 620, $baselineRain)],
            'future' => ['time' => $time, 'max' => $max, 'min' => $min, 'mean' => array_fill(0, 620, 12.0), 'precipitation' => array_fill(0, 620, $futureRain)],
        ];
        // A: -50 %; B: +100 %; C has no rain in its baseline, so it cannot give a percentage.
        $a = $model(2.0, 1.0);
        $b = $model(1.0, 2.0);
        $c = $model(0.0, 5.0);

        $result = new ClimateProjectionDeriver()->derive(
            ['A' => $a['baseline'], 'B' => $b['baseline'], 'C' => $c['baseline']],
            ['A' => $a['future'], 'B' => $b['future'], 'C' => $c['future']],
        );

        self::assertNotNull($result);
        self::assertEqualsWithDelta(25.0, $result['precipitationChange'], 1e-9);
        self::assertEqualsWithDelta(0.0, $result['uncertainty'], 1e-9);
    }

    public function testAModelWithoutDataForTheWindowOrTheBaselineIsLeftOut(): void
    {
        $time = [];
        for ($d = 0; $d < 360; ++$d) {
            $time[] = \sprintf('2050-%02d-%02d', 1 + intdiv($d, 30), 1 + $d % 30);
        }

        $full = ['time' => $time, 'max' => array_fill(0, 360, 36.0), 'min' => array_fill(0, 360, 10.0), 'mean' => array_fill(0, 360, 16.0), 'precipitation' => array_fill(0, 360, 1.0)];
        $empty = ['time' => $time, 'max' => array_fill(0, 360, null), 'min' => array_fill(0, 360, null), 'mean' => array_fill(0, 360, null), 'precipitation' => array_fill(0, 360, null)];
        $baseline = static fn (float $mean): array => ['time' => ['1991-01-01'], 'mean' => [$mean], 'precipitation' => [1.0]];

        $deriver = new ClimateProjectionDeriver();
        $result = $deriver->derive(
            ['A' => $baseline(14.0), 'B' => $baseline(0.0)],
            ['A' => $full, 'B' => $empty, 'C' => $full],
        );

        self::assertNotNull($result);
        self::assertEqualsWithDelta(360.0, $result['hotDays'], 1e-9);
        self::assertEqualsWithDelta(2.0, $result['warming'], 1e-9);

        self::assertNull($deriver->derive(['B' => $baseline(0.0)], ['B' => $empty]));
    }

    /**
     * @return array{list<string>, list<float>, list<float>} two years of 310 days, mild
     */
    private function twoYears(): array
    {
        $time = [];
        foreach ([2041, 2042] as $year) {
            for ($d = 0; $d < 310; ++$d) {
                $time[] = \sprintf('%d-%02d-%02d', $year, 1 + intdiv($d, 28), 1 + $d % 28);
            }
        }

        return [$time, array_fill(0, 620, 20.0), array_fill(0, 620, 10.0)];
    }
}
