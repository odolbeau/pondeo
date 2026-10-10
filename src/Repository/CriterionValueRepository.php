<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CriterionValue;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CriterionValue>
 */
class CriterionValueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CriterionValue::class);
    }
}
