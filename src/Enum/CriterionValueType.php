<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The PHP/SQL type of a Criterion's values, governing which typed column
 * of ZoneCriterionValue is populated for that criterion.
 */
enum CriterionValueType: string
{
    case Integer = 'integer';
    case Decimal = 'decimal';
    case Text = 'text';
    case Boolean = 'boolean';
}
