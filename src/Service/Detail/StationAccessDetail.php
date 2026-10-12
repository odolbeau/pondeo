<?php

declare(strict_types=1);

namespace App\Service\Detail;

final readonly class StationAccessDetail
{
    public function __construct(
        public string $name,
        public int $distanceMeters,
        public int $durationSeconds,
        /** @var list<StationJourneyDetail> */
        public array $journeys = [],
    ) {
    }

    /** A station inside the unité urbaine needs no route. */
    public function isInside(): bool
    {
        return 0 === $this->durationSeconds;
    }

    public function minutes(): int
    {
        return (int) round($this->durationSeconds / 60);
    }

    public function kilometers(): float
    {
        return round($this->distanceMeters / 1000, 1);
    }
}
