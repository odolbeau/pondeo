<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Commune;
use App\Entity\PoiCommuneLocation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Commune>
 */
class CommuneRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Commune::class);
    }

    /**
     * The commune whose boundary contains the given point — used at
     * write-time by PoiCommuneResolver to populate PoiCommuneLocation.
     */
    public function findContaining(string $pointEwkt): ?Commune
    {
        /** @var Commune|null $commune */
        $commune = $this->createQueryBuilder('commune')
            ->andWhere('ST_Contains(commune.boundary, ST_GeomFromEWKT(:point)) = true')
            ->setParameter('point', $pointEwkt)
            ->getQuery()
            ->getOneOrNullResult();

        return $commune;
    }

    /**
     * Pattern A: "zones containing at least one POI of brand X" — a plain
     * join through the precomputed PoiCommuneLocation, no live geometric
     * computation (see plan §4 / PoiCommuneLocation docblock).
     *
     * @return list<Commune>
     */
    public function findWithPoiBrand(string $brand): array
    {
        return $this->createQueryBuilder('commune')
            ->innerJoin(PoiCommuneLocation::class, 'location', 'WITH', 'location.commune = commune')
            ->innerJoin('location.poi', 'poi')
            ->andWhere('poi.brand = :brand')
            ->setParameter('brand', $brand)
            ->getQuery()
            ->getResult();
    }
}
