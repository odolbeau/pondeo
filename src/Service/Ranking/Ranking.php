<?php

declare(strict_types=1);

namespace App\Service\Ranking;

/**
 * An editorial ranking: a criterion code under a theme, with the sentence that sells it.
 *
 * The page ranks from the end the criterion says, but the personal ranking
 * (docs/personal-ranking.md) scores what people want: a high price tops the
 * price ranking yet counts against a city, so it is `invertedInScore`; the
 * population and the density are matters of taste, so not `scorable`.
 */
final readonly class Ranking
{
    public function __construct(
        public string $code,
        public string $theme,
        public string $title,
        public string $tagline,
        public RankingNote $note,
        public bool $scorable = true,
        public bool $invertedInScore = false,
        public ?string $scoreTitle = null,
    ) {
    }

    /** The title read as something to want, for the sliders and strengths of the personal ranking. */
    public function scoreLabel(): string
    {
        return $this->scoreTitle ?? $this->title;
    }
}
