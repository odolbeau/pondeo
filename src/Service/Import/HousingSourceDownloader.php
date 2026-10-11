<?php

declare(strict_types=1);

namespace App\Service\Import;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

/**
 * Refreshes `resources/geo/logement-recensement.csv` from INSEE's census
 * "Logements" dataset: dwellings by category and occupancy status, per
 * commune, for the census years.
 */
final readonly class HousingSourceDownloader
{
    // Every other dimension of the dataset is taken in total.
    private const array COMMON_FILTERS = [
        'RP_MEASURE' => 'DWELLINGS',
        'CARS' => '_T',
        'BUILD_END' => '_T',
        'NRG_SRC' => '_T',
        'TDW' => '_T',
        'CARPARK' => '_T',
        'NOR' => '_T',
        'L_STAY' => '_T',
    ];

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
        // OCS: dwelling category (main residence, vacant...). TSH: occupancy status of the main residences (100 owner, 221 social housing).
        $this->melodiCountsDownloader->download('DS_RP_LOGEMENT_PRINC', $targetDir.'/logement-recensement.csv', [
            'logements' => self::COMMON_FILTERS + ['OCS' => '_T', 'TSH' => '_T'],
            'vacants' => self::COMMON_FILTERS + ['OCS' => 'DW_VAC', 'TSH' => '_T'],
            'residences_principales' => self::COMMON_FILTERS + ['OCS' => 'DW_MAIN', 'TSH' => '_T'],
            'proprietaires' => self::COMMON_FILTERS + ['OCS' => 'DW_MAIN', 'TSH' => '100'],
            'hlm' => self::COMMON_FILTERS + ['OCS' => 'DW_MAIN', 'TSH' => '221'],
        ], $progress);
    }
}
