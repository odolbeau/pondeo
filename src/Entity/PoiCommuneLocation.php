<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\UuidIdTrait;
use App\Repository\PoiCommuneLocationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Precomputed POI→commune containment, resolved at write time (see
 * PoiCommuneResolver) via a single ST_Contains query per POI created or
 * moved, rather than recomputed live on every "which zones have POI X"
 * read. EPCI/département/région containment is then derived by joining
 * through Commune's own FK hierarchy — never duplicated per zone type.
 */
#[ORM\Entity(repositoryClass: PoiCommuneLocationRepository::class)]
class PoiCommuneLocation
{
    use TimestampableTrait;
    use UuidIdTrait;

    // Functionally 1:1 (a point belongs to exactly one commune, communes
    // don't overlap); modeled as ManyToOne+unique for symmetry with the
    // other join-style entities.
    #[ORM\ManyToOne(targetEntity: Poi::class)]
    #[ORM\JoinColumn(unique: true, nullable: false, onDelete: 'CASCADE')]
    private Poi $poi;

    #[ORM\ManyToOne(targetEntity: Commune::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Commune $commune;

    public function __construct(Poi $poi, Commune $commune)
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->poi = $poi;
        $this->commune = $commune;
    }

    public function getPoi(): Poi
    {
        return $this->poi;
    }

    public function getCommune(): Commune
    {
        return $this->commune;
    }

    public function setCommune(Commune $commune): static
    {
        $this->commune = $commune;

        return $this;
    }
}
