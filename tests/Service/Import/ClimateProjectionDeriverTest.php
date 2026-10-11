<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Service\Import\ClimateProjectionDeriver;
use PHPUnit\Framework\TestCase;

final class ClimateProjectionDeriverTest extends TestCase
{
    public function testDerivesYearlyCountsWindowMeansAndTheWarmingPerModel(): void
    {
        // Two years of 300 days: 3 hot days and 2 tropical nights in the first, 1 and 0 in the second.
        $time = [];
        $max = [];
        $min = [];
        foreach ([2041, 2042] as $year) {
            for ($d = 0; $d < 300; ++$d) {
                $time[] = \sprintf('%d-%02d-%02d', $year, 1 + intdiv($d, 28), 1 + $d % 28);
                $max[] = 20.0;
                $min[] = 10.0;
            }
        }

        foreach ([0, 1, 2] as $i) {
            $max[$i] = 36.0;
        }

        $max[300] = 35.1;
        $max[301] = 35.0; // not strictly above the threshold
        $min[5] = 21.0;
        $min[6] = 20.5;
        $min[7] = 20.0;
        $mean = array_fill(0, 600, 15.0);
        $max = array_values($max);
        $min = array_values($min);

        $future = [
            'A' => ['time' => $time, 'max' => $max, 'min' => $min, 'mean' => $mean],
            // The second model has hot days only in the first year, and a hotter baseline.
            'B' => ['time' => $time, 'max' => [...array_fill(0, 4, 40.0), ...array_fill(0, 596, 10.0)], 'min' => array_fill(0, 600, 5.0), 'mean' => array_fill(0, 600, 17.0)],
        ];
        $baseline = [
            'A' => ['time' => ['1991-01-01', '1991-01-02'], 'mean' => [12.0, 14.0]],
            'B' => ['time' => ['1991-01-01', '1991-01-02'], 'mean' => [10.0, null]],
        ];

        $result = new ClimateProjectionDeriver()->derive($baseline, $future);

        self::assertNotNull($result);
        // A: (3 + 1) / 2 = 2; B: (4 + 0) / 2 = 2.
        self::assertEqualsWithDelta(2.0, $result['hotDays'], 1e-9);
        // A: (2 + 0) / 2 = 1; B: 0.
        self::assertEqualsWithDelta(0.5, $result['tropicalNights'], 1e-9);
        // A: 15 - 13 = 2; B: 17 - 10 = 7.
        self::assertEqualsWithDelta(4.5, $result['warming'], 1e-9);
    }

    public function testAModelWithoutDataForTheWindowOrTheBaselineIsLeftOut(): void
    {
        $time = [];
        for ($d = 0; $d < 360; ++$d) {
            $time[] = \sprintf('2050-%02d-%02d', 1 + intdiv($d, 30), 1 + $d % 30);
        }

        $full = ['time' => $time, 'max' => array_fill(0, 360, 36.0), 'min' => array_fill(0, 360, 10.0), 'mean' => array_fill(0, 360, 16.0)];
        $empty = ['time' => $time, 'max' => array_fill(0, 360, null), 'min' => array_fill(0, 360, null), 'mean' => array_fill(0, 360, null)];

        $deriver = new ClimateProjectionDeriver();
        $result = $deriver->derive(
            ['A' => ['time' => ['1991-01-01'], 'mean' => [14.0]], 'B' => ['time' => ['1991-01-01'], 'mean' => [0.0]]],
            ['A' => $full, 'B' => $empty, 'C' => $full],
        );

        self::assertNotNull($result);
        self::assertEqualsWithDelta(360.0, $result['hotDays'], 1e-9);
        self::assertEqualsWithDelta(2.0, $result['warming'], 1e-9);

        self::assertNull($deriver->derive(['B' => ['time' => [], 'mean' => []]], ['B' => $empty]));
    }
}
