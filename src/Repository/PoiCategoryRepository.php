<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PoiCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<PoiCategory>
 */
class PoiCategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PoiCategory::class);
    }

    /**
     * @throws \LogicException when the category is not in the tree (see PoiCategoryTreeSynchronizer)
     */
    public function getByCode(string $code): PoiCategory
    {
        $category = $this->findOneBy(['code' => $code]);
        if (null === $category) {
            throw new \LogicException(\sprintf('The POI category "%s" does not exist: run app:import:poi-categories.', $code));
        }

        return $category;
    }

    /**
     * The root of the tree every category belongs to (itself for a root), by
     * category code. Read as plain values: hydrating the categories, with
     * their parents as lazy proxies, fails on UuidIdTrait's readonly id (see
     * PoiRepository::findByCategoryWithUniteUrbaine).
     *
     * @return array<string, array{code: string, label: string}>
     */
    public function findRootsByCode(): array
    {
        /** @var list<array{id: Uuid, code: string, label: string, parentId: Uuid|null}> $rows */
        $rows = $this->createQueryBuilder('category')
            ->select('category.id', 'category.code', 'category.label', 'IDENTITY(category.parent) AS parentId')
            ->getQuery()
            ->getArrayResult();

        $byId = [];
        foreach ($rows as $row) {
            $byId[(string) $row['id']] = $row;
        }

        $roots = [];
        foreach ($byId as $category) {
            $root = $category;
            // The bound only guards against a cycle in a hand-edited tree.
            for ($depth = 0; null !== $root['parentId'] && isset($byId[(string) $root['parentId']]) && $depth < \count($byId); ++$depth) {
                $root = $byId[(string) $root['parentId']];
            }

            $roots[$category['code']] = ['code' => $root['code'], 'label' => $root['label']];
        }

        return $roots;
    }

    /**
     * The whole tree of categories, ordered by label. Read as plain values, not
     * entities: hydrating them would clash with the ones already loaded with
     * lazy proxies for parents (see PoiRepository::findByCategoryWithUniteUrbaine).
     *
     * @return list<array{code: string, label: string, children: list<mixed>}>
     */
    public function findTree(): array
    {
        /** @var list<array{id: Uuid, code: string, label: string, parentId: Uuid|null}> $rows */
        $rows = $this->createQueryBuilder('category')
            ->select('category.id', 'category.code', 'category.label', 'IDENTITY(category.parent) AS parentId')
            ->orderBy('category.label')
            ->getQuery()
            ->getArrayResult();

        $childrenOf = [];
        foreach ($rows as $row) {
            $childrenOf[null === $row['parentId'] ? '' : (string) $row['parentId']][] = $row;
        }

        return $this->buildTree($childrenOf, '');
    }

    /**
     * @param array<string, list<array{id: Uuid, code: string, label: string, parentId: Uuid|null}>> $childrenOf
     *
     * @return list<array{code: string, label: string, children: list<mixed>}>
     */
    private function buildTree(array $childrenOf, string $parentId): array
    {
        return array_map(
            fn (array $row): array => ['code' => $row['code'], 'label' => $row['label'], 'children' => $this->buildTree($childrenOf, (string) $row['id'])],
            $childrenOf[$parentId] ?? [],
        );
    }
}
