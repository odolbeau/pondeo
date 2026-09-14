<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ZoneCriterionValue;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ZoneCriterionValue>
 */
class ZoneCriterionValueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ZoneCriterionValue::class);
    }
}
