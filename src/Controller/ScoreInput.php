<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\Validator\Constraints as Assert;

/** Query-string of the personal ranking: `w[prix_m2]=3` gives a criterion an importance from 0 (ignored) to 5. */
final readonly class ScoreInput
{
    public const int MAX_WEIGHT = 5;

    /**
     * @param array<string, int|string> $w
     */
    public function __construct(
        #[Assert\All([new Assert\Regex(pattern: '/^[0-5]$/', message: 'L\'importance d\'un critère va de 0 à 5.')])]
        public array $w = [],
        #[Assert\Positive]
        public int $page = 1,
    ) {
    }

    /** @return array<string, int> the importance of each criterion that counts, above 0 */
    public function weights(): array
    {
        return array_filter(array_map(intval(...), $this->w));
    }
}
