<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Enum\CriterionValueType;

/**
 * Imports the climate normals (see `docs/criteria-climate.md`), computed per
 * unité urbaine by {@see ClimateSourceDownloader}, so stored as is.
 */
final readonly class ClimateImporter
{
    public const string GROUP = 'Climat';

    /**
     * In display order, indexed by the CSV measure.
     *
     * @var array<string, array{label: string, type: CriterionValueType, unit: string, rankFromLowest: bool, comparable: bool}>
     */
    public const array CRITERIA = [
        'temperature_moyenne' => ['label' => 'Température moyenne', 'type' => CriterionValueType::Decimal, 'unit' => '°C', 'rankFromLowest' => false, 'comparable' => false],
        'jours_plus_30' => ['label' => 'Jours > 30 °C', 'type' => CriterionValueType::Integer, 'unit' => 'j/an', 'rankFromLowest' => true, 'comparable' => true],
        'jours_plus_35' => ['label' => 'Jours > 35 °C', 'type' => CriterionValueType::Integer, 'unit' => 'j/an', 'rankFromLowest' => true, 'comparable' => true],
        'nuits_tropicales' => ['label' => 'Nuits tropicales', 'type' => CriterionValueType::Integer, 'unit' => 'j/an', 'rankFromLowest' => true, 'comparable' => true],
        'jours_gel' => ['label' => 'Jours de gel', 'type' => CriterionValueType::Integer, 'unit' => 'j/an', 'rankFromLowest' => false, 'comparable' => false],
        'amplitude_thermique' => ['label' => 'Amplitude thermique', 'type' => CriterionValueType::Decimal, 'unit' => '°C', 'rankFromLowest' => false, 'comparable' => false],
        'precipitations' => ['label' => 'Précipitations', 'type' => CriterionValueType::Integer, 'unit' => 'mm/an', 'rankFromLowest' => false, 'comparable' => false],
        'jours_pluie' => ['label' => 'Jours de pluie', 'type' => CriterionValueType::Integer, 'unit' => 'j/an', 'rankFromLowest' => true, 'comparable' => true],
        'ensoleillement' => ['label' => 'Ensoleillement', 'type' => CriterionValueType::Integer, 'unit' => 'h/an', 'rankFromLowest' => false, 'comparable' => true],
        'jours_neige' => ['label' => 'Jours de neige', 'type' => CriterionValueType::Integer, 'unit' => 'j/an', 'rankFromLowest' => false, 'comparable' => false],
        'deficit_hydrique_estival' => ['label' => 'Déficit hydrique estival', 'type' => CriterionValueType::Integer, 'unit' => 'mm', 'rankFromLowest' => true, 'comparable' => true],
        'jours_vent_fort' => ['label' => 'Jours de vent fort', 'type' => CriterionValueType::Integer, 'unit' => 'j/an', 'rankFromLowest' => true, 'comparable' => true],
    ];

    public function __construct(private CsvCriterionImporter $csvCriterionImporter)
    {
    }

    /**
     * @return int number of (unité urbaine, period) values upserted
     */
    public function import(string $csvPath): int
    {
        $count = 0;
        $position = 0;
        foreach (self::CRITERIA as $code => ['label' => $label, 'type' => $type, 'unit' => $unit, 'rankFromLowest' => $rankFromLowest, 'comparable' => $comparable]) {
            $count += $this->csvCriterionImporter->import(new CriterionCsvSpec(
                code: $code,
                label: $label,
                valueType: $type,
                unit: $unit,
                communeCodeColumn: 'code_unite_urbaine',
                periodColumn: 'year',
                valueColumn: 'value',
                groupLabel: self::GROUP,
                filterColumn: 'measure',
                filterValue: $code,
                comparable: $comparable,
                position: $position++,
                rankFromLowest: $rankFromLowest,
                perUniteUrbaine: true,
            ), $csvPath);
        }

        return $count;
    }
}
