<?php

declare(strict_types=1);

namespace App\Service\Import;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

/**
 * Refreshes `resources/geo/diplomes-recensement.csv` from INSEE's census
 * "Diplômes et formation" dataset: the people aged 15 or more who have left
 * school, by highest degree, per commune, for the census years.
 */
final readonly class EducationSourceDownloader
{
    private const array COMMON_FILTERS = ['SEX' => '_T', 'AGE' => 'Y_GE15'];

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
        // 500T702_RP: the three higher-education levels (bac+2, bac+3/4, bac+5 or more) together.
        $this->melodiCountsDownloader->download('DS_RP_DIPLOMES_PRINC', $targetDir.'/diplomes-recensement.csv', [
            'superieur' => self::COMMON_FILTERS + ['EDUC' => '500T702_RP'],
            'non_scolarises' => self::COMMON_FILTERS + ['EDUC' => '_T'],
        ], $progress);
    }
}
