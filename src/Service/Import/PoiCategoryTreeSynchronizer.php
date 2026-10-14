<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Entity\PoiCategory;
use App\Repository\PoiCategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * Applies the tree of POI categories (`resources/poi-categories.yaml`): creates
 * the missing categories and updates the label and parent of the others, in
 * place. The POIs are not touched, and a category the file no longer lists is
 * left alone, as POIs may still point to it.
 */
final readonly class PoiCategoryTreeSynchronizer
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PoiCategoryRepository $poiCategoryRepository,
        #[Autowire('%kernel.project_dir%/resources/poi-categories.yaml')]
        private string $path,
    ) {
    }

    /**
     * @return int number of categories in the tree
     */
    public function synchronize(): int
    {
        $nodes = Yaml::parseFile($this->path);
        if (!\is_array($nodes)) {
            throw new \InvalidArgumentException(\sprintf('"%s" must hold a list of POI categories.', $this->path));
        }

        $seen = [];
        $count = $this->synchronizeLevel($nodes, null, $seen);
        $this->entityManager->flush();

        return $count;
    }

    /**
     * @param array<mixed>        $nodes
     * @param array<string, true> $seen
     */
    private function synchronizeLevel(array $nodes, ?PoiCategory $parent, array &$seen): int
    {
        $count = 0;
        foreach ($nodes as $node) {
            if (!\is_array($node) || !\is_string($node['code'] ?? null) || !\is_string($node['label'] ?? null)) {
                throw new \InvalidArgumentException('Each POI category needs a "code" and a "label".');
            }

            if (isset($seen[$node['code']])) {
                throw new \InvalidArgumentException(\sprintf('The POI category "%s" is listed twice.', $node['code']));
            }

            $seen[$node['code']] = true;

            $category = $this->poiCategoryRepository->findOneBy(['code' => $node['code']]);
            if (null === $category) {
                $category = new PoiCategory($node['code'], $node['label']);
                $this->entityManager->persist($category);
            }

            $category->setLabel($node['label']);
            $category->setParent($parent);

            $children = $node['children'] ?? [];
            if (!\is_array($children)) {
                throw new \InvalidArgumentException(\sprintf('The children of "%s" must be a list.', $node['code']));
            }

            $count += 1 + $this->synchronizeLevel($children, $category, $seen);
        }

        return $count;
    }
}
