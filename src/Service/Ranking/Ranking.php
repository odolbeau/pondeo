<?php

declare(strict_types=1);

namespace App\Service\Ranking;

/** An editorial ranking: a criterion code under a theme, with the sentence that sells it. */
final readonly class Ranking
{
    public function __construct(
        public string $code,
        public string $theme,
        public string $title,
        public string $tagline,
    ) {
    }
}
