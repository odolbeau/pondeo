<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Enum\CriterionValueType;
use App\Enum\ZoneType;

/**
 * Describes how to read a criterion's vendored CSV and upsert it via
 * {@see CsvCriterionImporter} — one instance per criterion, built by its
 * import command.
 */
final readonly class CriterionCsvSpec
{
    public function __construct(
        public string $code,
        public string $label,
        public CriterionValueType $valueType,
        public ZoneType $zoneType,
        public ?string $unit,
        public string $zoneCodeColumn,
        public string $periodColumn,
        public string $valueColumn,
    ) {
    }
}
