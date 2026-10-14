<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Enum\BoardGameGroup;
use App\Factory\PoiCategoryFactory;
use App\Factory\PoiFactory;
use App\Factory\UniteUrbaineFactory;
use App\Service\Import\BoardGamePoiImporter;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class BoardGamePoiImporterTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private const string ASSOCIATIONS_HEADER = "id,name,type,city,lat,lon,website\n";

    private const string STORES_HEADER = "id,name,type,address,lat,lon,website\n";

    public function testImportCreatesAssociationsUnderTheirTypeAndUniteUrbaineThenIsIdempotent(): void
    {
        $unit = UniteUrbaineFactory::createOne(['inseeCode' => '56501', 'boundary' => 'SRID=4326;MULTIPOLYGON(((-3 47,-3 48,-2 48,-2 47,-3 47)))']);
        $path = $this->writeTempFile(self::ASSOCIATIONS_HEADER
            .'ludopathes-vannes,Les Ludopathes,club,Vannes,47.5,-2.5,https://ludopathes.example'."\n"
            .'ludo-auray,Ludothèque d\'Auray,ludo,Auray,47.6,-2.4,'."\n"
            // Outside every unité urbaine: kept, attached to none.
            .'jeux-en-bois-lannion,Jeux En Bois,club,Lannion,48.5,-3.5,'."\n");

        self::assertSame(3, $this->importer()->import($path, BoardGameGroup::Association));
        self::assertSame(3, $this->poiCount());

        // Longitude first in EWKT.
        self::assertSame('POINT(-2.5 47.5)', $this->scalar("SELECT ST_AsText(location::geometry) FROM poi WHERE external_id = 'ludopathes-vannes'"));
        self::assertSame('{"website": "https://ludopathes.example"}', $this->scalar("SELECT attributes FROM poi WHERE external_id = 'ludopathes-vannes'"));
        self::assertNull($this->scalar("SELECT attributes FROM poi WHERE external_id = 'ludo-auray'"));
        self::assertSame('Vannes', $this->scalar("SELECT address FROM poi WHERE external_id = 'ludopathes-vannes'"));
        self::assertSame('board_game_club', $this->categoryOf('ludopathes-vannes'));
        self::assertSame('board_game_library', $this->categoryOf('ludo-auray'));
        // Each type is a child of the group's category.
        self::assertSame('board_game_association', $this->scalar("SELECT parent.code FROM poi_category child INNER JOIN poi_category parent ON parent.id = child.parent_id WHERE child.code = 'board_game_club'"));
        self::assertSame($unit->getId()->toRfc4122(), $this->scalar("SELECT unite_urbaine_id FROM poi WHERE external_id = 'ludopathes-vannes'"));
        self::assertNull($this->scalar("SELECT unite_urbaine_id FROM poi WHERE external_id = 'jeux-en-bois-lannion'"));

        self::assertSame(3, $this->importer()->import($path, BoardGameGroup::Association));
        self::assertSame(3, $this->poiCount());

        unlink($path);
    }

    public function testStoresKeepTheirAddressAndStayApartFromTheAssociations(): void
    {
        $associations = $this->writeTempFile(self::ASSOCIATIONS_HEADER.'a,Un club,club,Vannes,47.0,-2.0,'."\n");
        $stores = $this->writeTempFile(self::STORES_HEADER
            .'s1,Une boutique,shop,"25 route de Toulouse, 31470 Saint-Lys",43.5,1.17,https://boutique.example'."\n"
            .'s2,Un bar,cafe,"1 rue du Bar, 44000 Nantes",47.2,-1.5,'."\n");

        $this->importer()->import($associations, BoardGameGroup::Association);
        // The store group's import must not drop the associations.
        self::assertSame(2, $this->importer()->import($stores, BoardGameGroup::Store));

        self::assertSame(3, $this->poiCount());
        self::assertSame('25 route de Toulouse, 31470 Saint-Lys', $this->scalar("SELECT address FROM poi WHERE external_id = 's1'"));
        self::assertSame('board_game_shop', $this->categoryOf('s1'));
        self::assertSame('board_game_cafe', $this->categoryOf('s2'));

        unlink($associations);
        unlink($stores);
    }

    public function testReimportUpdatesInPlaceMovesAChangedTypeAndDropsPlacesTheSourceNoLongerLists(): void
    {
        $firstPath = $this->writeTempFile(self::ASSOCIATIONS_HEADER
            .'a,Ancien nom,club,Vannes,47.0,-2.0,u'."\n"
            .'b,Disparue,club,Auray,48.0,-1.0,u'."\n");
        $secondPath = $this->writeTempFile(self::ASSOCIATIONS_HEADER
            .'a,Nouveau nom,ludo,Vannes,47.5,-2.5,u'."\n");

        $this->importer()->import($firstPath, BoardGameGroup::Association);
        $originalId = $this->scalar("SELECT id FROM poi WHERE external_id = 'a'");
        $this->importer()->import($secondPath, BoardGameGroup::Association);

        self::assertSame(1, $this->poiCount());
        self::assertSame($originalId, $this->scalar("SELECT id FROM poi WHERE external_id = 'a'"));
        self::assertSame('Nouveau nom', $this->scalar("SELECT name FROM poi WHERE external_id = 'a'"));
        self::assertSame('board_game_library', $this->categoryOf('a'));

        unlink($firstPath);
        unlink($secondPath);
    }

    public function testImportTakesOverThePlacesOfTheFormerSingleCategory(): void
    {
        $former = PoiCategoryFactory::createOne(['code' => 'board_game_association', 'label' => 'Association de jeux de société']);
        PoiFactory::createOne(['category' => $former, 'name' => 'Ancien', 'externalId' => 'old']);
        PoiFactory::createOne(['category' => $former, 'name' => 'Repris', 'externalId' => 'kept']);

        $path = $this->writeTempFile(self::ASSOCIATIONS_HEADER.'kept,Repris,club,Vannes,47.0,-2.0,'."\n");
        $this->importer()->import($path, BoardGameGroup::Association);

        self::assertSame(1, $this->poiCount());
        self::assertSame('board_game_club', $this->categoryOf('kept'));

        unlink($path);
    }

    public function testAFileOfTheOtherGroupIsRejected(): void
    {
        $path = $this->writeTempFile(self::STORES_HEADER.'s1,Une boutique,shop,"1 rue, 31000 Toulouse",43.5,1.17,'."\n");

        $this->expectException(\LogicException::class);

        try {
            $this->importer()->import($path, BoardGameGroup::Association);
        } finally {
            unlink($path);
        }
    }

    private function importer(): BoardGamePoiImporter
    {
        $importer = self::getContainer()->get(BoardGamePoiImporter::class);
        \assert($importer instanceof BoardGamePoiImporter);

        return $importer;
    }

    private function connection(): Connection
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);

        return $entityManager->getConnection();
    }

    private function scalar(string $sql): mixed
    {
        return $this->connection()->fetchOne($sql);
    }

    private function categoryOf(string $externalId): mixed
    {
        return $this->connection()->fetchOne('SELECT category.code FROM poi INNER JOIN poi_category category ON category.id = poi.category_id WHERE poi.external_id = :id', ['id' => $externalId]);
    }

    private function poiCount(): int
    {
        $count = $this->connection()->fetchOne('SELECT COUNT(*) FROM poi');
        \assert(is_numeric($count));

        return (int) $count;
    }

    private function writeTempFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'board-game-importer-test-');
        \assert(false !== $path);
        file_put_contents($path, $content);

        return $path;
    }
}
