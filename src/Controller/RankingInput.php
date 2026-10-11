<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\Validator\Constraints as Assert;

/** Query-string of a ranking page: `ordre=inverse` shows the other end of the ranking. */
final readonly class RankingInput
{
    public function __construct(
        #[Assert\Positive]
        public int $page = 1,
        #[Assert\Choice(choices: ['defaut', 'inverse'])]
        public string $ordre = 'defaut',
    ) {
    }

    public function isReversed(): bool
    {
        return 'inverse' === $this->ordre;
    }
}
