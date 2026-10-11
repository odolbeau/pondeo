<?php

declare(strict_types=1);

namespace App\Service\Import;

/**
 * How the communes' values of an unité urbaine are combined into its own.
 */
enum Aggregation
{
    case Sum;
    /** Mean of the communes' values, each weighted by the spec's weight column. */
    case WeightedMean;
    case Max;
}
