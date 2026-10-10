<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\UuidIdTrait;
use App\Enum\CriterionValueType;
use App\Repository\CriterionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: CriterionRepository::class)]
class Criterion
{
    use TimestampableTrait;
    use UuidIdTrait;

    /** Stable machine key referenced by imports/fixtures/UI filters. */
    #[ORM\Column(length: 100, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Regex('/^[a-z0-9_]+$/', message: 'The criterion code must be lowercase snake_case.')]
    private string $code;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $label;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    /** e.g. "mm", "days", "hab." — null for non-numeric criteria. */
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $unit = null;

    /** Display section the criterion is listed under (e.g. "Démographie"); null means "Autres". */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $groupLabel = null;

    /**
     * Whether the detail page ranks a unité urbaine on this criterion and
     * shows the median. False for a raw count that only feeds a derived
     * criterion (shops per 1000 inhabitants): the count follows the city's
     * size, so ranking it says nothing.
     */
    #[ORM\Column(options: ['default' => true])]
    private bool $comparable = true;

    /** Order within its group on the detail page; criteria without one come after, alphabetically. */
    #[ORM\Column(nullable: true)]
    private ?int $position = null;

    #[ORM\Column(length: 20, enumType: CriterionValueType::class)]
    private CriterionValueType $valueType;

    public function __construct(string $code, string $label, CriterionValueType $valueType)
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->code = $code;
        $this->label = $label;
        $this->valueType = $valueType;
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getUnit(): ?string
    {
        return $this->unit;
    }

    public function setUnit(?string $unit): static
    {
        $this->unit = $unit;

        return $this;
    }

    public function getGroupLabel(): ?string
    {
        return $this->groupLabel;
    }

    public function getPosition(): ?int
    {
        return $this->position;
    }

    public function setPosition(?int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function isComparable(): bool
    {
        return $this->comparable;
    }

    public function setComparable(bool $comparable): static
    {
        $this->comparable = $comparable;

        return $this;
    }

    public function setGroupLabel(?string $groupLabel): static
    {
        $this->groupLabel = $groupLabel;

        return $this;
    }

    public function getValueType(): CriterionValueType
    {
        return $this->valueType;
    }

    public function setValueType(CriterionValueType $valueType): static
    {
        $this->valueType = $valueType;

        return $this;
    }
}
