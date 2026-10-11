<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Enum\CriterionValueType;

/**
 * Describes how to read a criterion's vendored CSV (one row = one commune,
 * period, value) and upsert it, aggregated per unité urbaine, via
 * {@see CsvCriterionImporter} — one instance per criterion, built by its
 * import command. When the CSV is already keyed by unité urbaine
 * ({@see self::$perUniteUrbaine}), values are stored as published instead.
 */
final readonly class CriterionCsvSpec
{
    public function __construct(
        public string $code,
        public string $label,
        public CriterionValueType $valueType,
        public ?string $unit,
        // Holds the unité urbaine code instead when $perUniteUrbaine is set.
        public string $communeCodeColumn,
        public string $periodColumn,
        public string $valueColumn,
        public ?string $groupLabel = null,
        // Restricts the import to the rows where this column equals the value
        // (e.g. one category of a CSV that carries several).
        public ?string $filterColumn = null,
        public ?string $filterValue = null,
        public bool $comparable = true,
        public ?int $position = null,
        public bool $rankFromLowest = false,
        public Aggregation $aggregation = Aggregation::Sum,
        // The weight of each row, required by Aggregation::WeightedMean.
        public ?string $weightColumn = null,
        // The CSV is already keyed by unité urbaine: nothing to aggregate.
        public bool $perUniteUrbaine = false,
    ) {
    }
}
