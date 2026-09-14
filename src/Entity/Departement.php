<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\UuidIdTrait;
use App\Enum\ZoneType;
use App\Repository\DepartementRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: DepartementRepository::class)]
#[ORM\Index(name: 'departement_boundary_gist', fields: ['boundary'], flags: ['spatial'])]
#[ORM\Index(name: 'departement_centroid_gist', fields: ['centroid'], flags: ['spatial'])]
class Departement implements ZoneInterface
{
    use TimestampableTrait;
    use UuidIdTrait;

    #[ORM\Column(length: 3, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Regex('/^(\d{2,3}|2[AB])$/', message: 'The INSEE département code must be 2-3 digits or "2A"/"2B".')]
    private readonly string $inseeCode;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $name;

    /** EWKT, see ZoneInterface::getBoundary(). */
    #[ORM\Column(type: 'geometry', nullable: true, options: ['geometry_type' => 'MULTIPOLYGON', 'srid' => 4326])]
    private ?string $boundary = null;

    /** Generated column — see Region::$centroid for the full explanation, including the `default` option workaround. */
    #[ORM\Column(type: 'geography', nullable: true, insertable: false, updatable: false, options: ['geometry_type' => 'POINT', 'srid' => 4326, 'default' => '(st_centroid(boundary))::geography'])]
    private ?string $centroid = null;

    /**
     * Immutable: a département changing région is a national reform-level
     * event (the last one was 2016's region merger), not a routine
     * re-import update. If it happens again, it's a deliberate one-off
     * operation, not something exposed as a generic setter.
     */
    // Deleting a Region must never silently cascade-delete its Departements.
    #[ORM\ManyToOne(targetEntity: Region::class, inversedBy: 'departements')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    #[Assert\NotNull]
    private readonly Region $region;

    /** @var Collection<int, Commune> */
    #[ORM\OneToMany(targetEntity: Commune::class, mappedBy: 'departement')]
    private Collection $communes;

    public function __construct(string $inseeCode, string $name, Region $region)
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->inseeCode = $inseeCode;
        $this->name = $name;
        $this->region = $region;
        $this->communes = new ArrayCollection();
    }

    public function getCode(): string
    {
        return $this->inseeCode;
    }

    public function getInseeCode(): string
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

    public function getType(): ZoneType
    {
        return ZoneType::Departement;
    }

    public function getRegion(): Region
    {
        return $this->region;
    }

    /** @return Collection<int, Commune> */
    public function getCommunes(): Collection
    {
        return $this->communes;
    }
}
