<?php

declare(strict_types=1);

namespace App\Service\Import;

/**
 * Imports the census age structure (see `docs/criteria-age-structure.md`):
 * the share of seven disjoint age brackets in the population.
 */
final readonly class AgeStructureImporter
{
    public const string GROUP = 'Âge';

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
                'population' => 'Population',
                'moins_15' => 'Moins de 15 ans',
                '15_24' => '15 à 24 ans',
                '25_39' => '25 à 39 ans',
                '40_54' => '40 à 54 ans',
                '55_64' => '55 à 64 ans',
                '65_79' => '65 à 79 ans',
                '80_plus' => '80 ans ou plus',
            ],
            [
                ['code' => 'part_moins_15_ans', 'label' => 'Moins de 15 ans', 'numerator' => 'moins_15', 'denominator' => 'population'],
                ['code' => 'part_15_24_ans', 'label' => '15 à 24 ans', 'numerator' => '15_24', 'denominator' => 'population'],
                ['code' => 'part_25_39_ans', 'label' => '25 à 39 ans', 'numerator' => '25_39', 'denominator' => 'population'],
                ['code' => 'part_40_54_ans', 'label' => '40 à 54 ans', 'numerator' => '40_54', 'denominator' => 'population'],
                ['code' => 'part_55_64_ans', 'label' => '55 à 64 ans', 'numerator' => '55_64', 'denominator' => 'population'],
                ['code' => 'part_65_79_ans', 'label' => '65 à 79 ans', 'numerator' => '65_79', 'denominator' => 'population'],
                ['code' => 'part_80_ans_et_plus', 'label' => '80 ans ou plus', 'numerator' => '80_plus', 'denominator' => 'population'],
            ],
        );
    }
}
