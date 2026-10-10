<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Enum\CriterionValueType;

/**
 * Describes how to read a criterion's vendored CSV (one row = one commune,
 * period, value) and upsert it, summed per unité urbaine, via
 * {@see CsvCriterionImporter} — one instance per criterion, built by its
 * import command.
 */
final readonly class CriterionCsvSpec
{
    public function __construct(
        public string $code,
        public string $label,
        public CriterionValueType $valueType,
        public ?string $unit,
        public string $communeCodeColumn,
        public string $periodColumn,
        public string $valueColumn,
    ) {
    }
}
