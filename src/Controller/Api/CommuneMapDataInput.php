<?php

declare(strict_types=1);

namespace App\Controller\Api;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Query-string filters for the commune map data endpoint. Population is the
 * only filter today; a future criterion is a new nullable property here.
 * Null means "not set" — the controller falls back to the full bounds.
 */
final readonly class CommuneMapDataInput
{
    public function __construct(
        #[Assert\PositiveOrZero]
        public ?int $populationMin = null,
        #[Assert\PositiveOrZero]
        public ?int $populationMax = null,
    ) {
    }
}
