<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\UuidIdTrait;
use App\Enum\Departement;
use App\Repository\CommuneRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Building block of an {@see UniteUrbaine}: only communes belonging to one
 * are stored (communes outside any unité urbaine are out of scope), so the
 * association is mandatory.
 */
#[ORM\Entity(repositoryClass: CommuneRepository::class)]
class Commune
{
    use TimestampableTrait;
    use UuidIdTrait;

    #[ORM\Column(length: 5, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(exactly: 5)]
    private readonly string $inseeCode;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $name;

    #[ORM\Column(length: 3, enumType: Departement::class)]
    private Departement $departement;

    /** EWKT MULTIPOLYGON; source of the unité urbaine's own boundary. */
    #[ORM\Column(type: 'geometry', nullable: true, options: ['geometry_type' => 'MULTIPOLYGON', 'srid' => 4326])]
    private ?string $boundary = null;

    #[ORM\ManyToOne(targetEntity: UniteUrbaine::class, inversedBy: 'communes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private UniteUrbaine $uniteUrbaine;

    public function __construct(string $inseeCode, string $name, Departement $departement, UniteUrbaine $uniteUrbaine)
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->inseeCode = $inseeCode;
        $this->name = $name;
        $this->departement = $departement;
        $this->uniteUrbaine = $uniteUrbaine;
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

    public function getDepartement(): Departement
    {
        return $this->departement;
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

    public function getUniteUrbaine(): UniteUrbaine
    {
        return $this->uniteUrbaine;
    }

    public function setUniteUrbaine(UniteUrbaine $uniteUrbaine): static
    {
        $this->uniteUrbaine = $uniteUrbaine;

        return $this;
    }
}
