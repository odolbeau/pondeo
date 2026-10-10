<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\UuidIdTrait;
use App\Enum\CriterionValueType;
use App\Repository\CriterionValueRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Fact table (EAV-style): one row = one criterion's value for one unité
 * urbaine, for one reference year ($period — e.g. an INSEE census year).
 *
 * Exactly one of the typed value columns is set (matching
 * $criterion->getValueType()) — enforced in validateExactlyOneValue() below
 * and, as defense-in-depth, by a CHECK constraint added by hand in the
 * migration (Doctrine attributes can't express CHECK constraints).
 */
#[ORM\Entity(repositoryClass: CriterionValueRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_criterion_value', columns: ['criterion_id', 'unite_urbaine_id', 'period'])]
#[Assert\Callback('validateExactlyOneValue')]
class CriterionValue
{
    use TimestampableTrait;
    use UuidIdTrait;

    // Deleting a Criterion that still has recorded values must fail loudly,
    // not cascade-delete silently.
    #[ORM\ManyToOne(targetEntity: Criterion::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Criterion $criterion;

    #[ORM\ManyToOne(targetEntity: UniteUrbaine::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private UniteUrbaine $uniteUrbaine;

    /** Reference year of this value (e.g. an INSEE census year). */
    #[ORM\Column(type: 'smallint')]
    private int $period;

    #[ORM\Column(type: 'decimal', precision: 15, scale: 5, nullable: true)]
    private ?string $valueDecimal = null;

    #[ORM\Column(nullable: true)]
    private ?int $valueInteger = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $valueText = null;

    #[ORM\Column(nullable: true)]
    private ?bool $valueBoolean = null;

    public function __construct(Criterion $criterion, UniteUrbaine $uniteUrbaine, int|float|string|bool $value, int $period)
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->criterion = $criterion;
        $this->uniteUrbaine = $uniteUrbaine;
        $this->period = $period;
        $this->assignValue($criterion->getValueType(), $value);
    }

    /** Update path for an existing (criterion, unité urbaine, period) row. */
    public function setValue(int|float|string|bool $value): void
    {
        $this->assignValue($this->criterion->getValueType(), $value);
    }

    private function assignValue(CriterionValueType $valueType, int|float|string|bool $value): void
    {
        match ($valueType) {
            CriterionValueType::Decimal => $this->valueDecimal = (string) $value,
            CriterionValueType::Integer => $this->valueInteger = (int) $value,
            CriterionValueType::Text => $this->valueText = (string) $value,
            CriterionValueType::Boolean => $this->valueBoolean = (bool) $value,
        };
    }

    public function getCriterion(): Criterion
    {
        return $this->criterion;
    }

    public function getUniteUrbaine(): UniteUrbaine
    {
        return $this->uniteUrbaine;
    }

    public function getPeriod(): int
    {
        return $this->period;
    }

    /**
     * The one typed value column matching the criterion's value type. The
     * exactly-one-populated invariant is enforced by
     * validateExactlyOneValue() and the DB CHECK constraint, so a null here
     * means that invariant was bypassed (e.g. by a fixture or direct SQL) —
     * fail loudly rather than silently returning a wrong 0/"".
     */
    public function getTypedValue(): int|float|string|bool
    {
        return match ($this->criterion->getValueType()) {
            CriterionValueType::Decimal => (float) $this->requireValue($this->valueDecimal, 'valueDecimal'),
            CriterionValueType::Integer => $this->requireValue($this->valueInteger, 'valueInteger'),
            CriterionValueType::Text => $this->requireValue($this->valueText, 'valueText'),
            CriterionValueType::Boolean => $this->requireValue($this->valueBoolean, 'valueBoolean'),
        };
    }

    private function requireValue(int|float|string|bool|null $value, string $field): int|float|string|bool
    {
        if (null === $value) {
            throw new \LogicException("{$field} is not set.");
        }

        return $value;
    }

    public function validateExactlyOneValue(ExecutionContextInterface $context): void
    {
        $valuesSet = array_filter(
            [$this->valueDecimal, $this->valueInteger, $this->valueText, $this->valueBoolean],
            static fn (mixed $v): bool => null !== $v,
        );
        if (1 !== \count($valuesSet)) {
            $context->buildViolation("Exactly one typed value column must be set, matching the criterion's value type.")
                ->atPath('criterion')
                ->addViolation();
        }
    }
}
