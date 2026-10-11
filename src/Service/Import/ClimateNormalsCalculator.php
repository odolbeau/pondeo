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
    ];

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
        foreach ($years as $indexes) {
            foreach ($this->measures($daily, $dates, $indexes) as $measure => $value) {
                if (null !== $value) {
                    $perYear[$measure][] = $value;
                }
            }
        }

        return array_map(static fn (array $values): float => array_sum($values) / \count($values), $perYear);
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
