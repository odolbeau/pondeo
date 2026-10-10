<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\UuidIdTrait;
use App\Repository\PoiRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: PoiRepository::class)]
#[ORM\Index(name: 'poi_location_gist', fields: ['location'], flags: ['spatial'])]
class Poi
{
    use TimestampableTrait;
    use UuidIdTrait;

    /** This specific establishment's own name (e.g. "Leroy Merlin Toulouse Blagnac"). */
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $name;

    #[ORM\ManyToOne(targetEntity: PoiCategory::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    #[Assert\NotNull]
    private PoiCategory $category;

    /**
     * Commercial chain/franchise key (e.g. "Leroy Merlin"), shared by every
     * outlet of that chain regardless of how each is individually named —
     * this is what brand-level filters ("villes avec un Leroy Merlin")
     * query, not `name`. Null for POIs with no chain (a power plant, a
     * train station...).
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $brand = null;

    /** EWKT point, e.g. "SRID=4326;POINT(...)" — see geography type. */
    #[ORM\Column(type: 'geography', options: ['geometry_type' => 'POINT', 'srid' => 4326])]
    #[Assert\NotNull]
    private string $location;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $address = null;

    /**
     * Containing unité urbaine, resolved at write time (see
     * PoiUniteUrbaineResolver) rather than on every read. Null for a POI
     * outside any unité urbaine (rural), which no comparison covers.
     */
    #[ORM\ManyToOne(targetEntity: UniteUrbaine::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?UniteUrbaine $uniteUrbaine = null;

    public function __construct(string $name, PoiCategory $category, string $location)
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->name = $name;
        $this->category = $category;
        $this->location = $location;
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

    public function getCategory(): PoiCategory
    {
        return $this->category;
    }

    public function setCategory(PoiCategory $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getBrand(): ?string
    {
        return $this->brand;
    }

    public function setBrand(?string $brand): static
    {
        $this->brand = $brand;

        return $this;
    }

    public function getLocation(): string
    {
        return $this->location;
    }

    public function setLocation(string $location): static
    {
        $this->location = $location;

        return $this;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(?string $address): static
    {
        $this->address = $address;

        return $this;
    }

    public function getUniteUrbaine(): ?UniteUrbaine
    {
        return $this->uniteUrbaine;
    }

    public function setUniteUrbaine(?UniteUrbaine $uniteUrbaine): static
    {
        $this->uniteUrbaine = $uniteUrbaine;

        return $this;
    }
}
