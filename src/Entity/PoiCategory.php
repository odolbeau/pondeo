<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\UuidIdTrait;
use App\Repository\PoiCategoryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/** Shallow hierarchical taxonomy for POIs (e.g. "Bricolage" > "Grande surface de bricolage"). */
#[ORM\Entity(repositoryClass: PoiCategoryRepository::class)]
class PoiCategory
{
    use TimestampableTrait;
    use UuidIdTrait;

    #[ORM\Column(length: 100, unique: true)]
    #[Assert\NotBlank]
    private string $code;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $label;

    // RESTRICT: a category with children must be explicitly re-parented or
    // emptied before deletion, never silently orphaned.
    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?self $parent = null;

    /** @var Collection<int, self> */
    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'parent')]
    private Collection $children;

    public function __construct(string $code, string $label)
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->code = $code;
        $this->label = $label;
        $this->children = new ArrayCollection();
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = $code;

        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function setParent(?self $parent): static
    {
        $this->parent = $parent;

        return $this;
    }

    /** @return Collection<int, self> */
    public function getChildren(): Collection
    {
        return $this->children;
    }
}
