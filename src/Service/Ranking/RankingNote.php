<?php

declare(strict_types=1);

namespace App\Service\Ranking;

/** The text shown above a ranking: a few plain-language paragraphs, then where the figures come from. */
final readonly class RankingNote
{
    /**
     * @param list<string> $paragraphs
     */
    public function __construct(
        public array $paragraphs,
        public string $source,
    ) {
    }
}
