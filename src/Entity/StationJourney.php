<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\UuidIdTrait;
use App\Enum\JourneyDestination;
use App\Repository\StationJourneyRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * What a railway station offers towards one destination city, on a reference
 * day: of all the journeys that day, only those with the fewest transfers
 * count (direct trains if there are any). All three figures are null when the
 * destination is out of reach (within the transfer cap).
 */
#[ORM\Entity(repositoryClass: StationJourneyRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_station_journey', columns: ['station_id', 'destination'])]
class StationJourney
{
    use TimestampableTrait;
    use UuidIdTrait;

    #[ORM\ManyToOne(targetEntity: Poi::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Poi $station;

    #[ORM\Column(length: 20, enumType: JourneyDestination::class)]
    private JourneyDestination $destination;

    /** The day the timetable was read for: offers vary with weekdays, holidays, works. */
    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $referenceDate;

    /** Fewest transfers over the day's journeys: 0 means a direct train. */
    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $transfers = null;

    /** Distinct departures that day at that transfer level (outbound only). */
    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $trainsPerDay = null;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $medianDurationMinutes = null;

    public function __construct(Poi $station, JourneyDestination $destination, \DateTimeImmutable $referenceDate)
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->station = $station;
        $this->destination = $destination;
        $this->referenceDate = $referenceDate;
    }

    public function getStation(): Poi
    {
        return $this->station;
    }

    public function getDestination(): JourneyDestination
    {
        return $this->destination;
    }

    public function getReferenceDate(): \DateTimeImmutable
    {
        return $this->referenceDate;
    }

    public function getTransfers(): ?int
    {
        return $this->transfers;
    }

    public function getTrainsPerDay(): ?int
    {
        return $this->trainsPerDay;
    }

    public function getMedianDurationMinutes(): ?int
    {
        return $this->medianDurationMinutes;
    }
}
