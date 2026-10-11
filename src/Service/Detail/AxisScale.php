<?php

declare(strict_types=1);

namespace App\Service\Detail;

/**
 * Round graduations for the axes of the detail page's SVG charts.
 */
final readonly class AxisScale
{
    /**
     * A round graduation (1, 2, 5, 10, 20, 50… times a power of ten) giving about $targetTicks marks up to $largest.
     */
    public static function niceStep(float $largest, int $targetTicks): int
    {
        $raw = max(1.0, $largest / $targetTicks);
        $magnitude = 10 ** floor(log10($raw));

        foreach ([1, 2, 5, 10] as $multiple) {
            if ($raw <= $multiple * $magnitude) {
                return max(1, (int) ($multiple * $magnitude));
            }
        }

        return max(1, (int) (10 * $magnitude));
    }
}
