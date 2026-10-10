<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Poi;
use App\Entity\UniteUrbaine;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UniteUrbaine>
 */
class UniteUrbaineRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UniteUrbaine::class);
    }

    /**
     * The unité urbaine whose boundary contains the given point — used at
     * write-time by PoiUniteUrbaineResolver to populate Poi::$uniteUrbaine.
     */
    public function findContaining(string $pointEwkt): ?UniteUrbaine
    {
        /** @var UniteUrbaine|null $uniteUrbaine */
        $uniteUrbaine = $this->createQueryBuilder('uu')
            ->andWhere('ST_Contains(uu.boundary, ST_GeomFromEWKT(:point)) = true')
            ->setParameter('point', $pointEwkt)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $uniteUrbaine;
    }

    /**
     * Pattern A: "unités urbaines containing at least one POI of brand X" —
     * a plain join on the precomputed Poi::$uniteUrbaine, no live geometric
     * computation.
     *
     * @return list<UniteUrbaine>
     */
    public function findWithPoiBrand(string $brand): array
    {
        return $this->createQueryBuilder('uu')
            ->innerJoin(Poi::class, 'poi', 'WITH', 'poi.uniteUrbaine = uu')
            ->andWhere('poi.brand = :brand')
            ->setParameter('brand', $brand)
            ->distinct()
            ->getQuery()
            ->getResult();
    }
}
