<?php

declare(strict_types=1);

namespace App\Service\Import;

/**
 * Imports the census employment figures (see `docs/criteria-employment.md`):
 * the unemployment and activity rates of the population aged 15-64.
 */
final readonly class EmploymentImporter
{
    public const string GROUP = 'Emploi';

    public function __construct(private CountRatioImporter $countRatioImporter)
    {
    }

    /**
     * @return int number of (unité urbaine, period) rate values upserted
     */
    public function import(string $csvPath): int
    {
        return $this->countRatioImporter->import(
            $csvPath,
            self::GROUP,
            ['actifs' => 'Actifs de 15 à 64 ans', 'chomeurs' => 'Chômeurs de 15 à 64 ans', 'population' => 'Population de 15 à 64 ans'],
            [
                ['code' => 'taux_chomage', 'label' => 'Taux de chômage', 'numerator' => 'chomeurs', 'denominator' => 'actifs'],
                ['code' => 'taux_activite', 'label' => 'Taux d\'activité', 'numerator' => 'actifs', 'denominator' => 'population'],
            ],
        );
    }
}
