<?php

declare(strict_types=1);

namespace App\Service\Import;

/**
 * Imports the census housing figures (see `docs/criteria-housing.md`): the
 * shares of owner-occupiers and of social housing among the main residences,
 * and the share of vacant housing among all dwellings.
 */
final readonly class HousingImporter
{
    public const string GROUP = 'Logement';

    public function __construct(private CountRatioImporter $countRatioImporter)
    {
    }

    /**
     * @return int number of (unité urbaine, period) share values upserted
     */
    public function import(string $csvPath): int
    {
        return $this->countRatioImporter->import(
            $csvPath,
            self::GROUP,
            [
                'logements' => 'Logements',
                'residences_principales' => 'Résidences principales',
                'proprietaires' => 'Résidences principales occupées par leur propriétaire',
                'hlm' => 'Résidences principales en logement social',
                'vacants' => 'Logements vacants',
            ],
            [
                ['code' => 'part_proprietaires', 'label' => 'Part de propriétaires', 'numerator' => 'proprietaires', 'denominator' => 'residences_principales'],
                ['code' => 'part_logement_social', 'label' => 'Part de logement social', 'numerator' => 'hlm', 'denominator' => 'residences_principales'],
                ['code' => 'part_logements_vacants', 'label' => 'Part de logements vacants', 'numerator' => 'vacants', 'denominator' => 'logements', 'rankFromLowest' => true],
            ],
        );
    }
}
