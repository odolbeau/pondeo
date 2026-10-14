<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Enum\BoardGameGroup;
use App\Enum\BoardGameType;
use App\Enum\HospitalType;
use App\Enum\TransitStopMode;
use App\Factory\PoiCategoryFactory;
use App\Factory\PoiFactory;
use App\Repository\PoiCategoryRepository;
use App\Service\Import\NuclearPlantImporter;
use App\Service\Import\PoiCategoryTreeSynchronizer;
use App\Service\Import\StationImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class PoiCategoryTreeSynchronizerTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function testTheVendoredTreeHoldsEveryCategoryTheImportersLookUp(): void
    {
        $synchronizer = self::getContainer()->get(PoiCategoryTreeSynchronizer::class);
        \assert($synchronizer instanceof PoiCategoryTreeSynchronizer);
        $synchronizer->synchronize();

        $repository = $this->repository();
        $codes = [
            HospitalType::EMERGENCY_CATEGORY_CODE,
            NuclearPlantImporter::CATEGORY_CODE,
            StationImporter::CATEGORY_CODE,
            'seveso_high',
            'seveso_low',
            ...array_map(static fn (HospitalType $type): string => $type->categoryCode(), HospitalType::cases()),
            ...array_map(static fn (TransitStopMode $mode): string => $mode->categoryCode(), TransitStopMode::cases()),
            ...array_map(static fn (BoardGameType $type): string => $type->categoryCode(), BoardGameType::cases()),
        ];
        foreach ($codes as $code) {
            self::assertSame($code, $repository->getByCode($code)->getCode());
        }

        // Emergency departments and hospitals share an ancestor, and so do Seveso sites and nuclear plants.
        $roots = $repository->findRootsByCode();
        self::assertSame('health_site', $roots[HospitalType::EMERGENCY_CATEGORY_CODE]['code']);
        self::assertSame('health_site', $roots['hospital_clinic']['code']);
        self::assertSame('risk_site', $roots['seveso_high']['code']);
        self::assertSame('risk_site', $roots[NuclearPlantImporter::CATEGORY_CODE]['code']);
        self::assertSame('seveso', $repository->getByCode('seveso_high')->getParent()?->getCode());
        self::assertSame('transport', $roots[StationImporter::CATEGORY_CODE]['code']);
        self::assertSame('transport', $roots['tram_stop']['code']);
        self::assertSame('board_game', $roots['board_game_club']['code']);
        self::assertSame('board_game', $roots['board_game_shop']['code']);
        foreach (BoardGameType::cases() as $type) {
            self::assertSame($type->group()->value, $repository->getByCode($type->categoryCode())->getParent()?->getCode());
        }

        self::assertSame('Boutique ou bar à jeux', $repository->getByCode(BoardGameGroup::Store->value)->getLabel());
    }

    public function testChangingTheTreeUpdatesTheCategoriesInPlaceWithoutTouchingThePois(): void
    {
        $category = PoiCategoryFactory::createOne(['code' => 'a', 'label' => 'Old label']);
        $poi = PoiFactory::createOne(['category' => $category]);

        $count = $this->synchronizer("- code: group\n  label: Group\n  children:\n    - { code: a, label: New label }\n    - { code: b, label: Another }\n")->synchronize();

        self::assertSame(3, $count);
        $connection = $this->entityManager()->getConnection();
        $row = $connection->fetchAssociative('SELECT c.label, p.code AS parent_code, c.id FROM poi_category c LEFT JOIN poi_category p ON p.id = c.parent_id WHERE c.code = :code', ['code' => 'a']);
        self::assertIsArray($row);
        self::assertSame('New label', $row['label']);
        self::assertSame('group', $row['parent_code']);
        self::assertSame(1, $connection->fetchOne("SELECT COUNT(*) FROM poi_category WHERE code = 'b'"));
        // Same category row: the POI still points to it.
        self::assertSame($row['id'], $connection->fetchOne('SELECT category_id FROM poi WHERE id = :id', ['id' => $poi->getId()->toRfc4122()]));
    }

    public function testACategoryTheTreeNoLongerListsIsLeftAlone(): void
    {
        PoiCategoryFactory::createOne(['code' => 'gone', 'label' => 'Gone']);

        $this->synchronizer("- code: a\n  label: A\n")->synchronize();

        self::assertSame('Gone', $this->repository()->getByCode('gone')->getLabel());
    }

    public function testACodeListedTwiceIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->synchronizer("- code: a\n  label: A\n  children:\n    - { code: a, label: Again }\n")->synchronize();
    }

    private function synchronizer(string $yaml): PoiCategoryTreeSynchronizer
    {
        $path = tempnam(sys_get_temp_dir(), 'poi-categories-test-');
        \assert(false !== $path);
        file_put_contents($path, $yaml);

        return new PoiCategoryTreeSynchronizer($this->entityManager(), $this->repository(), $path);
    }

    private function repository(): PoiCategoryRepository
    {
        $repository = self::getContainer()->get(PoiCategoryRepository::class);
        \assert($repository instanceof PoiCategoryRepository);

        return $repository;
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);

        return $entityManager;
    }
}
