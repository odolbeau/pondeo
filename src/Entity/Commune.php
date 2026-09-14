<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\UuidIdTrait;
use App\Enum\ZoneType;
use App\Repository\CommuneRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: CommuneRepository::class)]
#[ORM\Index(name: 'commune_boundary_gist', fields: ['boundary'], flags: ['spatial'])]
#[ORM\Index(name: 'commune_centroid_gist', fields: ['centroid'], flags: ['spatial'])]
#[Assert\Callback('validateRegionMatchesDepartement')]
class Commune implements ZoneInterface
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

    /** EWKT, see ZoneInterface::getBoundary(). */
    #[ORM\Column(type: 'geometry', nullable: true, options: ['geometry_type' => 'MULTIPOLYGON', 'srid' => 4326])]
    private ?string $boundary = null;

    /** Generated column — see Region::$centroid for the full explanation, including the `default` option workaround. */
    #[ORM\Column(type: 'geography', nullable: true, insertable: false, updatable: false, options: ['geometry_type' => 'POINT', 'srid' => 4326, 'default' => '(st_centroid(boundary))::geography'])]
    private ?string $centroid = null;

    /**
     * Immutable: a commune changing département is practically
     * unheard-of (a handful of cases in French history) — a deliberate
     * one-off operation if it ever happens again, not a routine
     * re-import update.
     */
    #[ORM\ManyToOne(targetEntity: Departement::class, inversedBy: 'communes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    #[Assert\NotNull]
    private readonly Departement $departement;

    /**
     * Denormalized read-path shortcut (avoids a join for the common
     * "communes of a région" query), always equal to `departement.region`
     * — enforced by validateRegionMatchesDepartement() below, not by a DB
     * constraint (see plan: cross-table checks aren't natively expressible
     * via Doctrine attributes, and the import pipeline is the sole writer).
     * Immutable for the same reason as $departement above.
     */
    #[ORM\ManyToOne(targetEntity: Region::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    #[Assert\NotNull]
    private readonly Region $region;

    /**
     * Nullable: a commune can be temporarily EPCI-less during a perimeter
     * reorganization. SET NULL (not RESTRICT) so a dissolved/merged EPCI
     * doesn't block deletion.
     */
    #[ORM\ManyToOne(targetEntity: Epci::class, inversedBy: 'communes')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Epci $epci = null;

    /**
     * $region is taken explicitly rather than derived from
     * `$departement->getRegion()` so that both arguments can safely be
     * unloaded Doctrine references (see ZoneImporter) — dereferencing one
     * to read the other would force a premature hydration.
     */
    public function __construct(string $inseeCode, string $name, Departement $departement, Region $region)
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->inseeCode = $inseeCode;
        $this->name = $name;
        $this->departement = $departement;
        $this->region = $region;
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
        return ZoneType::Commune;
    }

    public function getDepartement(): Departement
    {
        return $this->departement;
    }

    public function getRegion(): Region
    {
        return $this->region;
    }

    public function getEpci(): ?Epci
    {
        return $this->epci;
    }

    public function setEpci(?Epci $epci): static
    {
        $this->epci = $epci;

        return $this;
    }

    public function validateRegionMatchesDepartement(ExecutionContextInterface $context): void
    {
        if ($this->region !== $this->departement->getRegion()) {
            $context->buildViolation('The commune\'s région must match its département\'s région.')
                ->atPath('region')
                ->addViolation();
        }
    }
}
