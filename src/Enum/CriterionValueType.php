<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The PHP/SQL type of a Criterion's values, governing which typed column
 * of CriterionValue is populated for that criterion.
 */
enum CriterionValueType: string
{
    case Integer = 'integer';
    case Decimal = 'decimal';
    case Text = 'text';
    case Boolean = 'boolean';

    /** The typed column on `criterion_value` for this value type — equal to "value_{$this->value}" by construction. */
    public function valueColumn(): string
    {
        return 'value_'.$this->value;
    }

    /** Whether values of this type can be aggregated across communes (sum, mean, max), unlike a label or a flag. */
    public function isNumeric(): bool
    {
        return match ($this) {
            self::Integer, self::Decimal => true,
            self::Text, self::Boolean => false,
        };
    }
}
