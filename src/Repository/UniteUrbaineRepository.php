<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Poi;
use App\Entity\UniteUrbaine;
use App\Enum\Departement;
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

    /**
     * Every unité urbaine, grouped by département code order then by name;
     * units without a département come last under the empty key.
     *
     * @return array<string, array{departement: ?Departement, unites: list<UniteUrbaine>}>
     */
    public function findGroupedByDepartement(): array
    {
        /** @var list<UniteUrbaine> $unites */
        $unites = $this->createQueryBuilder('uu')
            ->addSelect('CASE WHEN uu.departement IS NULL THEN 1 ELSE 0 END AS HIDDEN nullLast')
            ->orderBy('nullLast', 'ASC')
            ->addOrderBy('uu.departement', 'ASC')
            ->addOrderBy('uu.name', 'ASC')
            ->getQuery()
            ->getResult();

        $groups = [];
        foreach ($unites as $unite) {
            $departement = $unite->getDepartement();
            $groups[$departement->value ?? ''] ??= ['departement' => $departement, 'unites' => []];
            $groups[$departement->value ?? '']['unites'][] = $unite;
        }

        return $groups;
    }

    /**
     * Name, code and département of every unité urbaine, for the search box
     * to filter in the browser (accent-insensitive, which the database can't
     * do without an extension).
     *
     * @return list<array{code: string, name: string, departement: ?Departement}>
     */
    public function findSearchIndex(): array
    {
        /** @var list<array{code: string, name: string, departement: ?Departement}> $rows */
        $rows = $this->createQueryBuilder('uu')
            ->select('uu.inseeCode AS code', 'uu.name', 'uu.departement')
            ->orderBy('uu.name', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return $rows;
    }
}
