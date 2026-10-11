<?php

declare(strict_types=1);

namespace App\Service\Detail;

/**
 * Population of an unité urbaine by sex and five-year age bracket, ready to
 * draw: brackets from the oldest to the youngest, each with the width of its
 * bars, relative to the largest bar of the pyramid.
 */
final readonly class AgePyramid
{
    private const int BRACKET_SIZE = 5;

    /**
     * @param list<array{label: string, women: int, men: int, womenWidth: float, menWidth: float}> $brackets oldest first
     */
    private function __construct(
        public int $year,
        public int $total,
        public array $brackets,
    ) {
    }

    /**
     * @param array{year: int, women: list<int>, men: list<int>} $stored
     */
    public static function fromStored(array $stored): self
    {
        $largest = max(1, ...$stored['women'], ...$stored['men']);
        $last = \count($stored['women']) - 1;

        $brackets = [];
        foreach ($stored['women'] as $i => $women) {
            $men = $stored['men'][$i] ?? 0;
            $brackets[] = [
                'label' => $i === $last ? \sprintf('%d et +', $i * self::BRACKET_SIZE) : \sprintf('%d-%d', $i * self::BRACKET_SIZE, $i * self::BRACKET_SIZE + self::BRACKET_SIZE - 1),
                'women' => $women,
                'men' => $men,
                'womenWidth' => round($women / $largest * 100, 1),
                'menWidth' => round($men / $largest * 100, 1),
            ];
        }

        return new self($stored['year'], array_sum($stored['women']) + array_sum($stored['men']), array_reverse($brackets));
    }
}
