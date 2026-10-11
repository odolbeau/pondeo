<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Enum\CriterionValueType;

/**
 * The risk criteria read from `risques-communes.csv`, one spec per measure.
 * Lower is better for all of them. Shares are weighted means of 0/100 flags,
 * so they read as the percentage of the unité urbaine's population.
 */
final class RiskCriteria
{
    /**
     * @return list<CriterionCsvSpec>
     */
    public static function specs(): array
    {
        $spec = static fn (string $measure, string $code, string $label, CriterionValueType $type, ?string $unit, Aggregation $aggregation, int $position): CriterionCsvSpec => new CriterionCsvSpec(
            code: $code,
            label: $label,
            valueType: $type,
            unit: $unit,
            communeCodeColumn: 'code_insee',
            periodColumn: 'year',
            valueColumn: 'value',
            groupLabel: 'Risques',
            filterColumn: 'measure',
            filterValue: $measure,
            position: $position,
            rankFromLowest: true,
            aggregation: $aggregation,
            weightColumn: Aggregation::WeightedMean === $aggregation ? 'population' : null,
        );

        return [
            $spec(RiskSourceDownloader::MEASURE_CATNAT, 'arretes_catnat', 'Arrêtés CatNat sur 20 ans (par commune)', CriterionValueType::Decimal, 'arrêtés', Aggregation::WeightedMean, 0),
            $spec(RiskSourceDownloader::MEASURE_FLOOD, 'part_zone_inondable', 'Population en zone inondable', CriterionValueType::Decimal, '%', Aggregation::WeightedMean, 1),
            $spec(RiskSourceDownloader::MEASURE_CLAY, 'argiles', 'Exposition au retrait-gonflement des argiles (1 faible à 3 forte)', CriterionValueType::Decimal, null, Aggregation::WeightedMean, 2),
            $spec(RiskSourceDownloader::MEASURE_FIRE, 'part_feux_foret', 'Population exposée aux feux de forêt', CriterionValueType::Decimal, '%', Aggregation::WeightedMean, 3),
            $spec(RiskSourceDownloader::MEASURE_SEISMIC, 'zone_sismique', 'Zone sismique (1 à 5)', CriterionValueType::Integer, null, Aggregation::Max, 4),
            $spec(RiskSourceDownloader::MEASURE_RADON, 'part_radon_fort', 'Population en zone à fort potentiel radon', CriterionValueType::Decimal, '%', Aggregation::WeightedMean, 5),
        ];
    }
}
