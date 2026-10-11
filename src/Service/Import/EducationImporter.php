<?php

declare(strict_types=1);

namespace App\Service\Import;

/**
 * Imports the census education figures (see `docs/criteria-education.md`):
 * the share of higher-education graduates among the people aged 15 or more
 * who have left school.
 */
final readonly class EducationImporter
{
    public const string GROUP = 'Diplômes';

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
            ['superieur' => 'Diplômés du supérieur', 'non_scolarises' => 'Population non scolarisée de 15 ans ou plus'],
            [['code' => 'part_diplomes_superieur', 'label' => 'Part de diplômés du supérieur', 'numerator' => 'superieur', 'denominator' => 'non_scolarises']],
        );
    }
}
