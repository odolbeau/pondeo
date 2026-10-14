<?php

declare(strict_types=1);

namespace App\Service\Detail;

use App\Enum\ShopType;

/**
 * The shops of an unité urbaine by type, laid out for the detail page: one
 * block per family (large stores, food, non-food), types from the most
 * numerous in the latest year, with their change over the whole series.
 */
final readonly class ShopTypeDetail
{
    private const array FAMILIES = [
        'B1' => 'Grandes surfaces',
        'B2' => 'Commerces alimentaires',
        'B3' => 'Commerces non alimentaires',
    ];

    /** Below this many shops at the start, a percentage says more about the size than about the trend. */
    public const int MIN_COUNT_FOR_PERCENT = 10;

    /**
     * @param list<int>                                                                                                               $years
     * @param list<array{label: string, rows: list<array{label: string, counts: list<int>, latest: int, delta: int, percent: ?int}>}> $families
     */
    private function __construct(
        public array $years,
        public array $families,
    ) {
    }

    /**
     * @param array{years: list<int>, counts: array<string, list<int>>} $stored
     */
    public static function fromStored(array $stored): self
    {
        $rowsByFamily = [];
        foreach ($stored['counts'] as $code => $counts) {
            $type = ShopType::tryFrom($code);
            if (null === $type) {
                continue;
            }

            $first = $counts[0] ?? 0;
            $last = $counts[\count($counts) - 1] ?? 0;
            $rowsByFamily[$type->subDomain()][] = [
                'label' => $type->label(),
                'counts' => $counts,
                'latest' => $last,
                'delta' => $last - $first,
                'percent' => $first >= self::MIN_COUNT_FOR_PERCENT ? (int) round(($last - $first) / $first * 100) : null,
            ];
        }

        $families = [];
        foreach (self::FAMILIES as $subDomain => $label) {
            $rows = $rowsByFamily[$subDomain] ?? [];
            if ([] === $rows) {
                continue;
            }

            usort($rows, static fn (array $a, array $b): int => $b['latest'] <=> $a['latest']);
            $families[] = ['label' => $label, 'rows' => $rows];
        }

        return new self($stored['years'], $families);
    }
}
