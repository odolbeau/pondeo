<?php

declare(strict_types=1);

namespace App\Service\Import;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

/**
 * Refreshes `resources/geo/structure-age-recensement.csv` from INSEE's census
 * "Évolution et structure de la population" dataset: the population by age
 * bracket, per commune, for the census years.
 */
final readonly class AgeStructureSourceDownloader
{
    private const array COMMON_FILTERS = ['SEX' => '_T', 'RP_MEASURE' => 'POP'];

    public function __construct(private MelodiCountsDownloader $melodiCountsDownloader)
    {
    }

    /**
     * @param \Closure(string): void $progress
     *
     * @throws ExceptionInterface when a request fails
     */
    public function download(string $targetDir, \Closure $progress): void
    {
        // The dataset also holds overlapping brackets (under 20, 20-64, 65 and over): left out so the shares add up to 100 %.
        $this->melodiCountsDownloader->download('DS_RP_POPULATION_PRINC', $targetDir.'/structure-age-recensement.csv', [
            'population' => self::COMMON_FILTERS + ['AGE' => '_T'],
            'moins_15' => self::COMMON_FILTERS + ['AGE' => 'Y_LT15'],
            '15_24' => self::COMMON_FILTERS + ['AGE' => 'Y15T24'],
            '25_39' => self::COMMON_FILTERS + ['AGE' => 'Y25T39'],
            '40_54' => self::COMMON_FILTERS + ['AGE' => 'Y40T54'],
            '55_64' => self::COMMON_FILTERS + ['AGE' => 'Y55T64'],
            '65_79' => self::COMMON_FILTERS + ['AGE' => 'Y65T79'],
            '80_plus' => self::COMMON_FILTERS + ['AGE' => 'Y_GE80'],
        ], $progress);
    }
}
