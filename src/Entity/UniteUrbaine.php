<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\UuidIdTrait;
use App\Enum\Departement;
use App\Repository\UniteUrbaineRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * INSEE "unité urbaine": a group of communes forming a continuous built-up
 * area — what people call an agglomération, and the unit this app compares.
 * Not an EPCI: the two perimeters overlap without matching (see
 * docs/urban-units.md).
 */
#[ORM\Entity(repositoryClass: UniteUrbaineRepository::class)]
#[ORM\Index(name: 'unite_urbaine_boundary_gist', fields: ['boundary'], flags: ['spatial'])]
#[ORM\Index(name: 'unite_urbaine_centroid_gist', fields: ['centroid'], flags: ['spatial'])]
class UniteUrbaine
{
    use TimestampableTrait;
    use UuidIdTrait;

    #[ORM\Column(length: 5, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(exactly: 5)]
    private readonly string $inseeCode;

    /** The ville-centre's name, so it collides with the commune's: display it as "Unité urbaine de …". */
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $name;

    /**
     * An unité urbaine can straddle several départements; this is the one of
     * its most populous commune, good enough for display and coarse filters.
     */
    #[ORM\Column(length: 3, nullable: true, enumType: Departement::class)]
    private ?Departement $departement = null;

    /** EWKT, union of the member communes' boundaries. */
    #[ORM\Column(type: 'geometry', nullable: true, options: ['geometry_type' => 'MULTIPOLYGON', 'srid' => 4326])]
    private ?string $boundary = null;

    /**
     * Generated column, computed by Postgres from `boundary`: read-only for
     * Doctrine (`insertable`/`updatable` false). The `default` option mirrors
     * the generation expression so `doctrine:schema:validate` sees no diff —
     * Doctrine has no first-class "generated column" mapping.
     */
    #[ORM\Column(type: 'geography', nullable: true, insertable: false, updatable: false, options: ['geometry_type' => 'POINT', 'srid' => 4326, 'default' => '(st_centroid(boundary))::geography'])]
    private ?string $centroid = null;

    /** @var Collection<int, Commune> */
    #[ORM\OneToMany(targetEntity: Commune::class, mappedBy: 'uniteUrbaine')]
    private Collection $communes;

    public function __construct(string $inseeCode, string $name)
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->inseeCode = $inseeCode;
        $this->name = $name;
        $this->communes = new ArrayCollection();
    }

    public function getCode(): string
    {
        return $this->inseeCode;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getDepartement(): ?Departement
    {
        return $this->departement;
    }

    public function setDepartement(?Departement $departement): static
    {
        $this->departement = $departement;

        return $this;
    }

    public function getBoundary(): ?string
    {
        return $this->boundary;
    }

    public function setBoundary(?string $boundary): static
    {
        $this->boundary = $boundary;

        return $this;
    }

    public function getCentroid(): ?string
    {
        return $this->centroid;
    }

    /** @return Collection<int, Commune> */
    public function getCommunes(): Collection
    {
        return $this->communes;
    }
}
