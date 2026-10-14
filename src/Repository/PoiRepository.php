<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Poi;
use App\Entity\PoiCategory;
use App\Entity\UniteUrbaine;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Poi>
 */
class PoiRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Poi::class);
    }

    /**
     * The unité urbaine is fetched in the same query, not left as a lazy
     * proxy: initialising one of those fails on UuidIdTrait's readonly id
     * (Doctrine ORM lazy ghost objects), which bites any bulk update of POIs.
     *
     * @return list<Poi>
     */
    public function findByCategoryWithUniteUrbaine(PoiCategory $category): array
    {
        return $this->createQueryBuilder('poi')
            ->addSelect('uniteUrbaine')
            ->leftJoin('poi.uniteUrbaine', 'uniteUrbaine')
            ->andWhere('poi.category = :category')
            ->setParameter('category', $category)
            ->getQuery()
            ->getResult();
    }

    /**
     * The categories having POIs in the unité urbaine.
     *
     * @return list<PoiCategory>
     */
    public function findCategories(UniteUrbaine $uniteUrbaine): array
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('category')
            ->from(PoiCategory::class, 'category')
            ->andWhere('EXISTS (SELECT 1 FROM '.Poi::class.' poi WHERE poi.category = category AND poi.uniteUrbaine = :uniteUrbaine)')
            ->setParameter('uniteUrbaine', $uniteUrbaine)
            ->orderBy('category.label')
            ->getQuery()
            ->getResult();
    }

    /**
     * @param list<PoiCategory> $categories
     *
     * @return list<Poi>
     */
    public function findByCategories(UniteUrbaine $uniteUrbaine, array $categories): array
    {
        return $this->createQueryBuilder('poi')
            ->andWhere('poi.uniteUrbaine = :uniteUrbaine')
            ->andWhere('poi.category IN (:categories)')
            ->setParameter('uniteUrbaine', $uniteUrbaine)
            ->setParameter('categories', $categories)
            ->orderBy('poi.name')
            ->getQuery()
            ->getResult();
    }
}
