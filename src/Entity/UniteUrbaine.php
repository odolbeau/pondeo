<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\UuidIdTrait;
use App\Enum\AttractionCategory;
use App\Enum\Departement;
use App\Repository\UniteUrbaineRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
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

    /**
     * Category, in the aires d'attraction zoning, of the most populous
     * commune. Null when that commune is unknown to the zoning (e.g. a recent
     * merger) — such a unit is never treated as a satellite.
     */
    #[ORM\Column(length: 2, nullable: true, enumType: AttractionCategory::class)]
    private ?AttractionCategory $attractionCategory = null;

    /** INSEE code and name of the aire d'attraction that commune belongs to (null when outside any). */
    #[ORM\Column(length: 3, nullable: true)]
    private ?string $attractionAreaCode = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $attractionAreaName = null;

    /** EWKT, union of the member communes' boundaries. */
    #[ORM\Column(type: 'geometry', nullable: true, options: ['geometry_type' => 'MULTIPOLYGON', 'srid' => 4326])]
    private ?string $boundary = null;

    /**
     * Population by sex and five-year age bracket of one census year, for the
     * pyramid of the detail page (see `docs/population-pyramid.md`): `women`
     * and `men` hold one count per bracket, from 0-4 up to 95 and over.
     *
     * @var array{year: int, women: list<int>, men: list<int>}|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $agePyramid = null;

    /**
     * Shops by type for the detail page (see `docs/criteria-commerces.md`):
     * `counts` holds, for each BPE type code, one count per year of `years`.
     *
     * @var array{years: list<int>, counts: array<string, list<int>>}|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $shopTypes = null;

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
    #[ORM\OrderBy(['name' => 'ASC'])]
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

    public function getAttractionCategory(): ?AttractionCategory
    {
        return $this->attractionCategory;
    }

    public function getAttractionAreaCode(): ?string
    {
        return $this->attractionAreaCode;
    }

    public function getAttractionAreaName(): ?string
    {
        return $this->attractionAreaName;
    }

    public function setAttraction(?AttractionCategory $category, ?string $areaCode, ?string $areaName): static
    {
        $this->attractionCategory = $category;
        $this->attractionAreaCode = $areaCode;
        $this->attractionAreaName = $areaName;

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

    /**
     * @return array{year: int, women: list<int>, men: list<int>}|null
     */
    public function getAgePyramid(): ?array
    {
        return $this->agePyramid;
    }

    /**
     * @param array{year: int, women: list<int>, men: list<int>}|null $agePyramid
     */
    public function setAgePyramid(?array $agePyramid): static
    {
        $this->agePyramid = $agePyramid;

        return $this;
    }

    /**
     * @return array{years: list<int>, counts: array<string, list<int>>}|null
     */
    public function getShopTypes(): ?array
    {
        return $this->shopTypes;
    }

    /**
     * @param array{years: list<int>, counts: array<string, list<int>>}|null $shopTypes
     */
    public function setShopTypes(?array $shopTypes): static
    {
        $this->shopTypes = $shopTypes;

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
