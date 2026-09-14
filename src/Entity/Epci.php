<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\UuidIdTrait;
use App\Enum\EpciType;
use App\Enum\ZoneType;
use App\Repository\EpciRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * An EPCI (Établissement Public de Coopération Intercommunale) groups
 * communes for shared competencies. No direct FK to Departement/Region:
 * an EPCI's territory can span several départements, so that relation
 * doesn't structurally hold — it's only derivable via member communes.
 */
#[ORM\Entity(repositoryClass: EpciRepository::class)]
#[ORM\Index(name: 'epci_boundary_gist', fields: ['boundary'], flags: ['spatial'])]
#[ORM\Index(name: 'epci_centroid_gist', fields: ['centroid'], flags: ['spatial'])]
class Epci implements ZoneInterface
{
    use TimestampableTrait;
    use UuidIdTrait;

    #[ORM\Column(length: 9, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(exactly: 9)]
    private readonly string $sirenCode;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $name;

    #[ORM\Column(length: 30, enumType: EpciType::class)]
    private EpciType $epciType;

    /** EWKT, see ZoneInterface::getBoundary(). */
    #[ORM\Column(type: 'geometry', nullable: true, options: ['geometry_type' => 'MULTIPOLYGON', 'srid' => 4326])]
    private ?string $boundary = null;

    /** Generated column — see Region::$centroid for the full explanation, including the `default` option workaround. */
    #[ORM\Column(type: 'geography', nullable: true, insertable: false, updatable: false, options: ['geometry_type' => 'POINT', 'srid' => 4326, 'default' => '(st_centroid(boundary))::geography'])]
    private ?string $centroid = null;

    /** @var Collection<int, Commune> */
    #[ORM\OneToMany(targetEntity: Commune::class, mappedBy: 'epci')]
    private Collection $communes;

    public function __construct(string $sirenCode, string $name, EpciType $epciType)
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->sirenCode = $sirenCode;
        $this->name = $name;
        $this->epciType = $epciType;
        $this->communes = new ArrayCollection();
    }

    public function getCode(): string
    {
        return $this->sirenCode;
    }

    public function getSirenCode(): string
    {
        return $this->sirenCode;
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

    public function getEpciType(): EpciType
    {
        return $this->epciType;
    }

    public function setEpciType(EpciType $epciType): static
    {
        $this->epciType = $epciType;

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
        return ZoneType::Epci;
    }

    /** @return Collection<int, Commune> */
    public function getCommunes(): Collection
    {
        return $this->communes;
    }
}
