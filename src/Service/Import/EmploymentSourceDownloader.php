<?php

declare(strict_types=1);

namespace App\Service\Import;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

/**
 * Refreshes `resources/geo/emploi-recensement.csv` from INSEE's census
 * "Population active et chômage" dataset: the population aged 15-64 by
 * employment status, per commune, for the census years.
 */
final readonly class EmploymentSourceDownloader
{
    private const array COMMON_FILTERS = ['SEX' => '_T', 'AGE' => 'Y15T64', 'EDUC' => '_T'];

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
        $this->melodiCountsDownloader->download('DS_RP_EMPLOI_LR_PRINC', $targetDir.'/emploi-recensement.csv', [
            'actifs' => self::COMMON_FILTERS + ['EMPSTA_ENQ' => '1T2'],
            'chomeurs' => self::COMMON_FILTERS + ['EMPSTA_ENQ' => '2'],
            'population' => self::COMMON_FILTERS + ['EMPSTA_ENQ' => '_T'],
        ], $progress);
    }
}
