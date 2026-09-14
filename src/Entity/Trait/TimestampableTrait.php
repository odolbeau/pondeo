<?php

declare(strict_types=1);

namespace App\Entity\Trait;

use Doctrine\ORM\Mapping as ORM;

/**
 * Each using class must set `$this->createdAt = $this->updatedAt = new
 * \DateTimeImmutable();` in its own constructor (like UuidIdTrait's id —
 * not through an `#[ORM\PrePersist]` hook here) because Doctrine lifecycle
 * events, including PrePersist, are disabled by Foundry during factory
 * `create()` calls, which would otherwise leave these columns null and
 * violate their NOT NULL constraint when loading fixtures.
 */
trait TimestampableTrait
{
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    #[ORM\PreUpdate]
    public function touchUpdatedAt(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
