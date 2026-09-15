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
 * Fact table (EAV-style): one row = one criterion's value for one zone, for
 * one reference year ($period — e.g. an INSEE census year).
 *
 * Exactly one of $commune/$epci/$departement/$region is set (matching
 * $zoneType), and exactly one of the typed value columns is set (matching
 * $criterion->getValueType()) — enforced in validateExactlyOneZoneAndValue()
 * below and, as defense-in-depth, by a CHECK constraint added by hand in
 * the migration (see plan §6/§2.6 — Doctrine attributes can't express
 * cross-column CHECK constraints).
 *
 * Uniqueness is NOT declared via #[ORM\UniqueConstraint] here: a plain SQL
 * UNIQUE constraint across commune_id/epci_id/departement_id/region_id
 * doesn't actually constrain anything, because SQL never considers two
 * NULLs equal — and for any given row, three of those four columns are
 * always NULL. Enforced instead by 4 partial unique indexes (one per zone
 * type, `WHERE <fk> IS NOT NULL`), added by hand in the migration — same
 * "Doctrine attributes can't express this" pattern as the GiST indexes.
 */
#[ORM\Entity(repositoryClass: ZoneCriterionValueRepository::class)]
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

    /** Reference year of this value (e.g. an INSEE census year). */
    #[ORM\Column(type: 'smallint')]
    private int $period;

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

    private function __construct(Criterion $criterion, ZoneInterface $zone, int|float|string|bool $value, int $period)
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->criterion = $criterion;
        $this->zoneType = $zone->getType();
        $this->period = $period;
        $this->assignZone($zone);
        $this->assignValue($criterion->getValueType(), $value);
    }

    public static function forZone(Criterion $criterion, ZoneInterface $zone, int|float|string|bool $value, int $period): self
    {
        return new self($criterion, $zone, $value, $period);
    }

    /** Update path for an existing (criterion, zone, period) row — see forZone(). */
    public function setValue(int|float|string|bool $value): void
    {
        $this->assignValue($this->criterion->getValueType(), $value);
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

    public function getPeriod(): int
    {
        return $this->period;
    }

    public function getZone(): ZoneInterface
    {
        $zone = $this->commune ?? $this->epci ?? $this->departement ?? $this->region;
        if (null === $zone) {
            throw new \LogicException('ZoneCriterionValue has no zone set.');
        }

        return $zone;
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
