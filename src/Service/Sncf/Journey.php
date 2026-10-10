<?php

declare(strict_types=1);

namespace App\Service\Sncf;

final readonly class Journey
{
    public function __construct(
        public \DateTimeImmutable $departure,
        public int $durationSeconds,
        public int $transfers,
    ) {
    }

    public function durationMinutes(): int
    {
        return (int) round($this->durationSeconds / 60);
    }
}
