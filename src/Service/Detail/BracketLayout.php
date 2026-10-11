<?php

declare(strict_types=1);

namespace App\Service\Detail;

/**
 * How a {@see BracketDistribution} is drawn: a column per bracket, suited to a
 * few brackets with short labels (income brackets), or a row per bracket, to
 * many or long ones (socio-professional categories).
 */
enum BracketLayout
{
    case Columns;
    case Rows;
}
