<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** What the last successful run of an import command was fed with, to skip it while nothing changed. */
#[ORM\Entity]
class ImportState
{
    #[ORM\Column]
    private \DateTimeImmutable $importedAt;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 100)]
        private readonly string $name,
        #[ORM\Column(length: 64)]
        private string $sourceHash,
    ) {
        $this->importedAt = new \DateTimeImmutable();
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSourceHash(): string
    {
        return $this->sourceHash;
    }

    public function getImportedAt(): \DateTimeImmutable
    {
        return $this->importedAt;
    }
}
