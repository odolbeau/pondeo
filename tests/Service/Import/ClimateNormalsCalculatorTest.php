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

    public function testRunsTrendAndSummerFigures(): void
    {
        // 1991: a 4-day and a 2-day hot spell; dry days 1-3 then 5; one 25 mm day.
        // 1992: one 3-day hot spell in July; every day dry.
        $dates = ['1991-07-01', '1991-07-02', '1991-07-03', '1991-07-04', '1991-07-05', '1991-07-06', '1991-12-01', '1992-07-01', '1992-07-02', '1992-07-03'];
        $normals = new ClimateNormalsCalculator()->normals([
            'time' => $dates,
            'temperature_2m_max' => [31, 32, 33, 34, 20, 31, 5, 31, 31, 31],
            'temperature_2m_mean' => [10, 10, 10, 10, 10, 10, 10, 12, 12, 12],
            'precipitation_sum' => [0, 0, 0, 25, 0, 0, 0, 0, 0, 0],
            'wet_bulb_temperature_2m_max' => [25, 20, 20, 20, 20, 20, 20, 10, 10, 10],
            'apparent_temperature_max' => [35, 40, 36, 30, 30, 30, 5, 38, 30, 30],
            'vapour_pressure_deficit_max' => [1.0, 2.0, 3.0, 2.0, 2.0, 2.0, 9.0, 1.0, 1.0, 1.0],
            'soil_moisture_0_to_7cm_mean' => [0.2, 0.2, 0.2, 0.2, 0.2, 0.2, 0.5, 0.4, 0.4, 0.4],
            'soil_moisture_7_to_28cm_mean' => [0.3, 0.3, 0.3, 0.3, 0.3, 0.3, 0.5, 0.4, 0.4, 0.4],
        ]);

        // Hot spells of at least 3 days: 1 in 1991 (the 4-day one), 1 in 1992.
        self::assertEqualsWithDelta(1.0, $normals['vagues_chaleur'], 0.0001);
        // Longest dry run: 3 days in 1991 (the 25 mm day cuts it, then 3 more), 3 in 1992.
        self::assertEqualsWithDelta((3 + 3) / 2, $normals['serie_seche_max'], 0.0001);
        self::assertEqualsWithDelta(0.5, $normals['jours_pluie_forte'], 0.0001);
        self::assertEqualsWithDelta(0.5, $normals['jours_chaleur_humide'], 0.0001);
        self::assertEqualsWithDelta((40 + 38) / 2, $normals['temperature_ressentie_max'], 0.0001);
        // June to August only: the December day is left out.
        self::assertEqualsWithDelta((2.0 + 1.0) / 2, $normals['deficit_pression_vapeur'], 0.0001);
        self::assertEqualsWithDelta((25.0 + 40.0) / 2, $normals['humidite_sol_ete'], 0.0001);
        // Annual means of 10 and 12 °C a year apart: 2 °C per year.
        self::assertEqualsWithDelta(20.0, $normals['tendance_temperature'], 0.0001);
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
