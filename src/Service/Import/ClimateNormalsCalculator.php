<?php

declare(strict_types=1);

namespace App\Service\Import;

/**
 * Turns the daily series of one location (see `docs/criteria-climate.md`)
 * into its climate normals: each measure is derived per calendar year, then
 * averaged over the years. A missing day (null) is skipped, never counted as
 * zero.
 */
final readonly class ClimateNormalsCalculator
{
    public const array DAILY_VARIABLES = [
        'temperature_2m_max',
        'temperature_2m_min',
        'temperature_2m_mean',
        'precipitation_sum',
        'snowfall_sum',
        'sunshine_duration',
        'wind_gusts_10m_max',
        'et0_fao_evapotranspiration',
        'apparent_temperature_max',
        'wet_bulb_temperature_2m_max',
        'vapour_pressure_deficit_max',
        'soil_moisture_0_to_7cm_mean',
        'soil_moisture_7_to_28cm_mean',
    ];

    // °C of daily maximum wet-bulb temperature; see the distribution in docs/criteria-climate.md.
    public const float WET_BULB_THRESHOLD = 22.0;

    /**
     * @param array<string, list<mixed>> $daily Open-Meteo `daily` block: the dates under `time`, then one list per variable
     *
     * @return array<string, float> measure code (see {@see ClimateImporter::CRITERIA}) => normal
     */
    public function normals(array $daily): array
    {
        /** @var list<string> $dates */
        $dates = $daily['time'];

        $years = [];
        foreach ($dates as $i => $date) {
            $years[(int) substr($date, 0, 4)][] = $i;
        }

        $perYear = [];
        $annualTemperature = [];
        foreach ($years as $year => $indexes) {
            foreach ($this->measures($daily, $dates, $indexes) as $measure => $value) {
                if (null !== $value) {
                    $perYear[$measure][] = $value;
                }
            }

            $mean = $this->mean(array_map(static fn (int $i): mixed => $daily['temperature_2m_mean'][$i] ?? null, $indexes));
            if (null !== $mean) {
                $annualTemperature[$year] = $mean;
            }
        }

        $normals = array_map(static fn (array $values): float => array_sum($values) / \count($values), $perYear);

        $trend = $this->decadalTrend($annualTemperature);
        if (null !== $trend) {
            $normals['tendance_temperature'] = $trend;
        }

        return $normals;
    }

    /**
     * Least-squares slope of the annual values, per decade.
     *
     * @param array<int, float> $byYear
     */
    private function decadalTrend(array $byYear): ?float
    {
        $n = \count($byYear);
        if ($n < 2) {
            return null;
        }

        $meanYear = array_sum(array_keys($byYear)) / $n;
        $meanValue = array_sum($byYear) / $n;
        $covariance = 0.0;
        $variance = 0.0;
        foreach ($byYear as $year => $value) {
            $covariance += ($year - $meanYear) * ($value - $meanValue);
            $variance += ($year - $meanYear) ** 2;
        }

        return 10 * $covariance / $variance;
    }

    /**
     * Mean of the daily values of June to August.
     *
     * @param array<string, list<mixed>> $daily
     * @param list<string>               $dates
     * @param list<int>                  $indexes
     * @param list<string>               $variables averaged together when several
     */
    private function summerMean(array $daily, array $dates, array $indexes, array $variables): ?float
    {
        $values = [];
        foreach ($indexes as $i) {
            if (!\in_array((int) substr($dates[$i], 5, 2), [6, 7, 8], true)) {
                continue;
            }

            $day = [];
            foreach ($variables as $variable) {
                $value = $daily[$variable][$i] ?? null;
                if (!is_numeric($value)) {
                    continue 2;
                }

                $day[] = (float) $value;
            }

            $values[] = array_sum($day) / \count($day);
        }

        return $this->mean($values);
    }

    /**
     * Longest run of consecutive days matching the predicate, and the number
     * of runs reaching $minimum days. A missing day ends a run.
     *
     * @param list<mixed>           $values
     * @param \Closure(float): bool $predicate
     *
     * @return array{longest: int, episodes: int}
     */
    private function runs(array $values, \Closure $predicate, int $minimum): array
    {
        $longest = 0;
        $episodes = 0;
        $current = 0;
        foreach ([...$values, null] as $value) {
            if (is_numeric($value) && $predicate((float) $value)) {
                ++$current;

                continue;
            }

            $longest = max($longest, $current);
            if ($current >= $minimum) {
                ++$episodes;
            }

            $current = 0;
        }

        return ['longest' => $longest, 'episodes' => $episodes];
    }

    /**
     * @param array<string, list<mixed>> $daily
     * @param list<string>               $dates
     * @param list<int>                  $indexes the days of one year
     *
     * @return array<string, float|null>
     */
    private function measures(array $daily, array $dates, array $indexes): array
    {
        $column = static fn (string $variable): array => array_map(static fn (int $i): mixed => $daily[$variable][$i] ?? null, $indexes);

        $tmax = $column('temperature_2m_max');
        $tmin = $column('temperature_2m_min');
        $precipitation = $column('precipitation_sum');
        $seconds = $this->sum($column('sunshine_duration'));
        $hasTemperature = [] !== $this->known($tmax);
        $hasPrecipitation = [] !== $this->known($precipitation);
        $apparent = $this->known($column('apparent_temperature_max'));

        return [
            'temperature_moyenne' => $this->mean($column('temperature_2m_mean')),
            'jours_plus_30' => $this->count($tmax, static fn (float $v): bool => $v > 30),
            'jours_plus_35' => $this->count($tmax, static fn (float $v): bool => $v > 35),
            'nuits_tropicales' => $this->count($tmin, static fn (float $v): bool => $v > 20),
            'jours_gel' => $this->count($tmin, static fn (float $v): bool => $v < 0),
            'amplitude_thermique' => $this->amplitude($daily, $dates, $indexes),
            'precipitations' => $this->sum($precipitation),
            'jours_pluie' => $this->count($precipitation, static fn (float $v): bool => $v >= 1),
            'ensoleillement' => null === $seconds ? null : $seconds / 3600,
            'jours_neige' => $this->count($column('snowfall_sum'), static fn (float $v): bool => $v >= 1),
            'deficit_hydrique_estival' => $this->summerDeficit($daily, $dates, $indexes),
            'jours_vent_fort' => $this->count($column('wind_gusts_10m_max'), static fn (float $v): bool => $v >= 60),
            'vagues_chaleur' => $hasTemperature ? (float) $this->runs($tmax, static fn (float $v): bool => $v > 30, 3)['episodes'] : null,
            'serie_seche_max' => $hasPrecipitation ? (float) $this->runs($precipitation, static fn (float $v): bool => $v < 1, 1)['longest'] : null,
            'jours_pluie_forte' => $this->count($precipitation, static fn (float $v): bool => $v >= 20),
            'jours_chaleur_humide' => $this->count($column('wet_bulb_temperature_2m_max'), static fn (float $v): bool => $v > self::WET_BULB_THRESHOLD),
            'temperature_ressentie_max' => [] === $apparent ? null : max($apparent),
            'deficit_pression_vapeur' => $this->summerMean($daily, $dates, $indexes, ['vapour_pressure_deficit_max']),
            // m³/m³ shown as a percentage: the stored decimals are too coarse for 0.xx values.
            'humidite_sol_ete' => null === ($soil = $this->summerMean($daily, $dates, $indexes, ['soil_moisture_0_to_7cm_mean', 'soil_moisture_7_to_28cm_mean'])) ? null : $soil * 100,
        ];
    }

    /**
     * Hottest minus coldest monthly mean temperature of the year.
     *
     * @param array<string, list<mixed>> $daily
     * @param list<string>               $dates
     * @param list<int>                  $indexes
     */
    private function amplitude(array $daily, array $dates, array $indexes): ?float
    {
        $months = [];
        foreach ($indexes as $i) {
            $value = $daily['temperature_2m_mean'][$i] ?? null;
            if (is_numeric($value)) {
                $months[(int) substr($dates[$i], 5, 2)][] = (float) $value;
            }
        }

        if ([] === $months) {
            return null;
        }

        $means = array_map(static fn (array $values): float => array_sum($values) / \count($values), $months);

        return max($means) - min($means);
    }

    /**
     * Evapotranspiration minus precipitation over June to August: positive
     * when the soil dries out.
     *
     * @param array<string, list<mixed>> $daily
     * @param list<string>               $dates
     * @param list<int>                  $indexes
     */
    private function summerDeficit(array $daily, array $dates, array $indexes): ?float
    {
        $deficit = null;
        foreach ($indexes as $i) {
            if (!\in_array((int) substr($dates[$i], 5, 2), [6, 7, 8], true)) {
                continue;
            }

            $et0 = $daily['et0_fao_evapotranspiration'][$i] ?? null;
            $rain = $daily['precipitation_sum'][$i] ?? null;
            if (is_numeric($et0) && is_numeric($rain)) {
                $deficit = ($deficit ?? 0.0) + (float) $et0 - (float) $rain;
            }
        }

        return $deficit;
    }

    /**
     * @param list<mixed> $values
     *
     * @return list<float>
     */
    private function known(array $values): array
    {
        return array_values(array_map(floatval(...), array_filter($values, is_numeric(...))));
    }

    /**
     * @param list<mixed> $values
     */
    private function mean(array $values): ?float
    {
        $values = $this->known($values);

        return [] === $values ? null : array_sum($values) / \count($values);
    }

    /**
     * @param list<mixed> $values
     */
    private function sum(array $values): ?float
    {
        $values = $this->known($values);

        return [] === $values ? null : array_sum($values);
    }

    /**
     * @param list<mixed>           $values
     * @param \Closure(float): bool $predicate
     */
    private function count(array $values, \Closure $predicate): ?float
    {
        $values = $this->known($values);

        return [] === $values ? null : (float) \count(array_filter($values, $predicate));
    }
}
