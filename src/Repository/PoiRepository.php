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
     * @return list<array{category: PoiCategory, total: int}>
     */
    public function countByCategory(UniteUrbaine $uniteUrbaine): array
    {
        /** @var list<array{0: PoiCategory, total: int|string}> $rows */
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('category', 'COUNT(poi.id) AS total')
            ->from(PoiCategory::class, 'category')
            ->innerJoin(Poi::class, 'poi', 'WITH', 'poi.category = category')
            ->andWhere('poi.uniteUrbaine = :uniteUrbaine')
            ->setParameter('uniteUrbaine', $uniteUrbaine)
            ->groupBy('category.id')
            ->orderBy('category.label')
            ->getQuery()
            ->getResult();

        return array_map(
            static fn (array $row): array => ['category' => $row[0], 'total' => (int) $row['total']],
            $rows,
        );
    }

    /**
     * @return list<Poi>
     */
    public function findByCategory(UniteUrbaine $uniteUrbaine, PoiCategory $category, int $limit): array
    {
        return $this->createQueryBuilder('poi')
            ->andWhere('poi.uniteUrbaine = :uniteUrbaine')
            ->andWhere('poi.category = :category')
            ->setParameter('uniteUrbaine', $uniteUrbaine)
            ->setParameter('category', $category)
            ->orderBy('poi.name')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
