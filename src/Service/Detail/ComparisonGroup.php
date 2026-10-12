<?php

declare(strict_types=1);

namespace App\Service\Detail;

final readonly class ComparisonGroup
{
    /** @param list<ComparisonRow> $rows */
    public function __construct(
        public string $label,
        public array $rows,
    ) {
    }
}
