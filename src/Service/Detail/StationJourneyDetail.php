<?php

declare(strict_types=1);

namespace App\Service\Detail;

use App\Enum\JourneyDestination;

/** What a station offers towards one destination city (see docs/station-journeys.md). */
final readonly class StationJourneyDetail
{
    public function __construct(
        public JourneyDestination $destination,
        public ?int $transfers,
        public ?int $trainsPerDay,
        public ?int $medianDurationMinutes,
        public \DateTimeImmutable $referenceDate,
    ) {
    }

    public function isReachable(): bool
    {
        return null !== $this->transfers;
    }
}
