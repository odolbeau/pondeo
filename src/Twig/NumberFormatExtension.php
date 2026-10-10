<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Attribute\AsTwigFilter;

/** French formatting of numbers (non-breaking space for thousands, comma for decimals) and criterion values. */
final class NumberFormatExtension
{
    #[AsTwigFilter('fr_number')]
    public function format(int|float $number): string
    {
        if (\is_int($number)) {
            return number_format($number, 0, ',', "\u{a0}");
        }

        $formatted = number_format($number, 2, ',', "\u{a0}");

        return rtrim(rtrim($formatted, '0'), ',');
    }

    #[AsTwigFilter('criterion_value')]
    public function formatCriterionValue(int|float|string|bool $value): string
    {
        return match (true) {
            \is_bool($value) => $value ? 'Oui' : 'Non',
            \is_string($value) => $value,
            default => $this->format($value),
        };
    }
}
