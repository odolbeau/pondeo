<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\UuidIdTrait;
use App\Enum\CriterionValueType;
use App\Enum\ZoneType;
use App\Repository\ZoneCriterionValueRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Fact table (EAV-style): one row = one criterion's value for one zone.
 *
 * Exactly one of $commune/$epci/$departement/$region is set (matching
 * $zoneType), and exactly one of the typed value columns is set (matching
 * $criterion->getValueType()) — enforced in validateExactlyOneZoneAndValue()
 * below and, as defense-in-depth, by a CHECK constraint added by hand in
 * the migration (see plan §6/§2.6 — Doctrine attributes can't express
 * cross-column CHECK constraints).
 */
#[ORM\Entity(repositoryClass: ZoneCriterionValueRepository::class)]
#[ORM\UniqueConstraint(columns: ['criterion_id', 'zone_type', 'commune_id', 'epci_id', 'departement_id', 'region_id'])]
#[Assert\Callback('validateExactlyOneZoneAndValue')]
class ZoneCriterionValue
{
    use TimestampableTrait;
    use UuidIdTrait;

    // Deleting a Criterion that still has recorded values must fail loudly,
    // not cascade-delete silently — see plan §2.6.
    #[ORM\ManyToOne(targetEntity: Criterion::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Criterion $criterion;

    #[ORM\Column(length: 20, enumType: ZoneType::class)]
    private ZoneType $zoneType;

    #[ORM\ManyToOne(targetEntity: Commune::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Commune $commune = null;

    #[ORM\ManyToOne(targetEntity: Epci::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Epci $epci = null;

    #[ORM\ManyToOne(targetEntity: Departement::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Departement $departement = null;

    #[ORM\ManyToOne(targetEntity: Region::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Region $region = null;

    #[ORM\Column(type: 'decimal', precision: 15, scale: 5, nullable: true)]
    private ?string $valueDecimal = null;

    #[ORM\Column(nullable: true)]
    private ?int $valueInteger = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $valueText = null;

    #[ORM\Column(nullable: true)]
    private ?bool $valueBoolean = null;

    private function __construct(Criterion $criterion, ZoneInterface $zone, int|float|string|bool $value)
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->criterion = $criterion;
        $this->zoneType = $zone->getType();
        $this->assignZone($zone);
        $this->assignValue($criterion->getValueType(), $value);
    }

    public static function forZone(Criterion $criterion, ZoneInterface $zone, int|float|string|bool $value): self
    {
        return new self($criterion, $zone, $value);
    }

    private function assignZone(ZoneInterface $zone): void
    {
        match (true) {
            $zone instanceof Commune => $this->commune = $zone,
            $zone instanceof Epci => $this->epci = $zone,
            $zone instanceof Departement => $this->departement = $zone,
            $zone instanceof Region => $this->region = $zone,
            default => throw new \LogicException('Unsupported zone type: '.$zone::class),
        };
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

    public function getZoneType(): ZoneType
    {
        return $this->zoneType;
    }

    public function getZone(): ZoneInterface
    {
        return $this->commune ?? $this->epci ?? $this->departement ?? $this->region
            ?? throw new \LogicException('ZoneCriterionValue has no zone set.');
    }

    /**
     * The one typed value column matching the criterion's value type. The
     * exactly-one-populated invariant is enforced by
     * validateExactlyOneZoneAndValue() and the DB CHECK constraint, so a
     * null here means that invariant was bypassed (e.g. by a fixture or
     * direct SQL) — fail loudly rather than silently returning a wrong 0/"".
     */
    public function getTypedValue(): int|float|string|bool
    {
        return match ($this->criterion->getValueType()) {
            CriterionValueType::Decimal => null !== $this->valueDecimal ? (float) $this->valueDecimal : throw new \LogicException('valueDecimal is not set.'),
            CriterionValueType::Integer => $this->valueInteger ?? throw new \LogicException('valueInteger is not set.'),
            CriterionValueType::Text => $this->valueText ?? throw new \LogicException('valueText is not set.'),
            CriterionValueType::Boolean => $this->valueBoolean ?? throw new \LogicException('valueBoolean is not set.'),
        };
    }

    public function validateExactlyOneZoneAndValue(ExecutionContextInterface $context): void
    {
        $zonesSet = array_filter([$this->commune, $this->epci, $this->departement, $this->region]);
        if (1 !== \count($zonesSet)) {
            $context->buildViolation('Exactly one zone (commune/epci/departement/region) must be set.')
                ->atPath('zoneType')
                ->addViolation();
        }

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
