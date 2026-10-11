<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\UuidIdTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One of the few railway stations closest to an unité urbaine, with the car
 * route from its centre (see docs/criteria-station-access.md). A station
 * inside the unit is 0 m / 0 s.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_station_access', columns: ['unite_urbaine_id', 'station_id'])]
class StationAccess
{
    use TimestampableTrait;
    use UuidIdTrait;

    #[ORM\ManyToOne(targetEntity: UniteUrbaine::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private UniteUrbaine $uniteUrbaine;

    #[ORM\ManyToOne(targetEntity: Poi::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Poi $station;

    /** 1 is the fastest to reach. */
    #[ORM\Column(type: 'smallint')]
    private int $position;

    #[ORM\Column]
    private int $distanceMeters;

    #[ORM\Column]
    private int $durationSeconds;

    /** Year the route was computed. */
    #[ORM\Column(type: 'smallint')]
    private int $period;

    public function __construct(UniteUrbaine $uniteUrbaine, Poi $station, int $position, int $distanceMeters, int $durationSeconds, int $period)
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->uniteUrbaine = $uniteUrbaine;
        $this->station = $station;
        $this->position = $position;
        $this->distanceMeters = $distanceMeters;
        $this->durationSeconds = $durationSeconds;
        $this->period = $period;
    }
}
