<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Service\Import\ClimateNormalsCalculator;
use PHPUnit\Framework\TestCase;

final class ClimateNormalsCalculatorTest extends TestCase
{
    public function testNormalsAreYearlyFiguresAveragedOverTheYears(): void
    {
        // Two years of three days each: one summer day, two winter days.
        $normals = new ClimateNormalsCalculator()->normals([
            'time' => ['2019-01-10', '2019-01-11', '2019-07-15', '2020-01-10', '2020-01-11', '2020-07-15'],
            'temperature_2m_max' => [5, 6, 36, 4, 5, 31],
            'temperature_2m_min' => [-2, 1, 21, -1, -3, 15],
            'temperature_2m_mean' => [2, 3, 28, 1, 0, 22],
            'precipitation_sum' => [0.5, 3.0, 0.0, 10.0, 2.0, 4.0],
            'snowfall_sum' => [0.0, 2.0, 0.0, 0.0, 0.0, 0.0],
            'sunshine_duration' => [3600, 7200, 36000, 0, 3600, 32400],
            'wind_gusts_10m_max' => [30, 65, 20, 40, 61, 59],
            'et0_fao_evapotranspiration' => [0.5, 0.5, 6.0, 0.5, 0.5, 5.0],
        ]);

        // 2019 then 2020 for each figure.
        self::assertEqualsWithDelta((11 + 7.6667) / 2, $normals['temperature_moyenne'], 0.01);
        self::assertEqualsWithDelta(1.0, $normals['jours_plus_30'], 0.0001); // 1 and 1
        self::assertEqualsWithDelta(0.5, $normals['jours_plus_35'], 0.0001); // 1 and 0
        self::assertEqualsWithDelta(0.5, $normals['nuits_tropicales'], 0.0001); // 1 and 0
        self::assertEqualsWithDelta(1.5, $normals['jours_gel'], 0.0001); // 1 and 2
        // Monthly means: 2.5 / 28 in 2019, 0.5 / 22 in 2020.
        self::assertEqualsWithDelta((25.5 + 21.5) / 2, $normals['amplitude_thermique'], 0.0001);
        self::assertEqualsWithDelta((3.5 + 16.0) / 2, $normals['precipitations'], 0.0001);
        self::assertEqualsWithDelta(2.0, $normals['jours_pluie'], 0.0001); // 1 and 3
        self::assertEqualsWithDelta((13 + 10) / 2, $normals['ensoleillement'], 0.0001); // hours
        self::assertEqualsWithDelta(0.5, $normals['jours_neige'], 0.0001);
        // Only July counts: 6.0 - 0.0 in 2019, 5.0 - 4.0 in 2020.
        self::assertEqualsWithDelta((6.0 + 1.0) / 2, $normals['deficit_hydrique_estival'], 0.0001);
        self::assertEqualsWithDelta(1.0, $normals['jours_vent_fort'], 0.0001); // 1 and 1
    }

    public function testMissingDaysAreSkippedAndAllMissingMeansNoNormal(): void
    {
        $normals = new ClimateNormalsCalculator()->normals([
            'time' => ['2020-01-10', '2020-01-11'],
            'temperature_2m_max' => [null, null],
            'temperature_2m_min' => [-1, null],
            'temperature_2m_mean' => [4, null],
        ]);

        self::assertEqualsWithDelta(4.0, $normals['temperature_moyenne'], 0.0001);
        self::assertEqualsWithDelta(1.0, $normals['jours_gel'], 0.0001);
        self::assertArrayNotHasKey('jours_plus_30', $normals);
        self::assertArrayNotHasKey('precipitations', $normals);
    }
}
