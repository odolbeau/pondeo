<?php

declare(strict_types=1);

namespace App\Entity\Trait;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * UUIDv7 primary key, generated in PHP at construction time (not by the
 * database) so the identifier is available immediately, before the first
 * flush. Symfony 8.1's default Uid\Factory already produces UUIDv7, but it
 * is called explicitly here for clarity: v7 is time-ordered, which keeps
 * Postgres' primary key B-tree index well-behaved on insert, unlike a
 * fully random v4.
 *
 * Each using class must assign `$this->id = Uuid::v7();` in its own
 * constructor (rather than through a shared helper method here) so PHPStan
 * can see the readonly property being set directly in the constructor.
 */
trait UuidIdTrait
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private readonly Uuid $id;

    public function getId(): Uuid
    {
        return $this->id;
    }
}
