<?php

declare(strict_types=1);

namespace App\Service\Import;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

/**
 * Refreshes `resources/geo/pcs-recensement.csv` from INSEE's census
 * "Évolution et structure de la population" dataset: the population aged 15
 * or more by socio-professional category, per commune, for the census years.
 */
final readonly class SocioProfessionalSourceDownloader
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
        $statuses = ['population' => self::COMMON_FILTERS + ['PCS' => '_T']];
        foreach (SocioProfessionalImporter::pcsCodes() as $status => $pcs) {
            $statuses[$status] = self::COMMON_FILTERS + ['PCS' => $pcs];
        }

        $this->melodiCountsDownloader->download('DS_RP_POPULATION_COMP', $targetDir.'/pcs-recensement.csv', $statuses, $progress);
    }
}
