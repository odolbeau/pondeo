<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Factory\UniteUrbaineFactory;
use App\Service\Import\TransitStopImporter;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class TransitStopImporterTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private const string HEADER = "id,name,mode,lat,lon,code_commune\n";

    public function testImportCreatesStationsInTheCategoryOfTheirModeThenIsIdempotent(): void
    {
        $vannes = UniteUrbaineFactory::createOne(['inseeCode' => '56501', 'boundary' => 'SRID=4326;MULTIPOLYGON(((-3 47,-3 48,-2 48,-2 47,-3 47)))']);
        $path = $this->writeTempFile(self::HEADER
            .'EQ1,Gare,tram,47.5,-2.5,56260'."\n"
            .'EQ2,Bellecour,metro,47.6,-2.4,56260'."\n"
            // Outside every unité urbaine: kept, attached to none.
            .'EQ3,Rural,tram,49.6852237,1.7743058,60001'."\n");

        self::assertSame(3, $this->importer()->import($path));
        self::assertSame(3, $this->poiCount());

        self::assertSame('tram_stop', $this->categoryCode('EQ1'));
        self::assertSame('metro_station', $this->categoryCode('EQ2'));
        // Longitude first in EWKT: a swapped pair would put Vannes in the Indian Ocean.
        self::assertSame('POINT(-2.5 47.5)', $this->connection()->fetchOne("SELECT ST_AsText(location::geometry) FROM poi WHERE external_id = 'EQ1'"));
        self::assertSame($vannes->getId()->toRfc4122(), $this->connection()->fetchOne("SELECT unite_urbaine_id FROM poi WHERE external_id = 'EQ1'"));
        self::assertNull($this->connection()->fetchOne("SELECT unite_urbaine_id FROM poi WHERE external_id = 'EQ3'"));

        self::assertSame(3, $this->importer()->import($path));
        self::assertSame(3, $this->poiCount());
        // Re-importing must not duplicate the categories.
        self::assertSame(1, $this->categoryCount('tram_stop'));

        unlink($path);
    }

    public function testReimportUpdatesInPlaceMovesAStationWhoseModeChangedAndDropsTheOnesTheSourceNoLongerLists(): void
    {
        $firstPath = $this->writeTempFile(self::HEADER
            .'EQ1,Ancien nom,tram,47.0,-2.0,56001'."\n"
            .'EQ2,Disparue,metro,48.0,-1.0,56002'."\n");
        $secondPath = $this->writeTempFile(self::HEADER
            .'EQ1,Nouveau nom,metro,47.5,-2.5,56001'."\n");

        $this->importer()->import($firstPath);
        $originalId = $this->connection()->fetchOne("SELECT id FROM poi WHERE external_id = 'EQ1'");
        $this->importer()->import($secondPath);

        self::assertSame(1, $this->poiCount());
        self::assertSame($originalId, $this->connection()->fetchOne("SELECT id FROM poi WHERE external_id = 'EQ1'"));
        self::assertSame('Nouveau nom', $this->connection()->fetchOne("SELECT name FROM poi WHERE external_id = 'EQ1'"));
        self::assertSame('metro_station', $this->categoryCode('EQ1'));
        self::assertSame('POINT(-2.5 47.5)', $this->connection()->fetchOne("SELECT ST_AsText(location::geometry) FROM poi WHERE external_id = 'EQ1'"));

        unlink($firstPath);
        unlink($secondPath);
    }

    private function importer(): TransitStopImporter
    {
        $importer = self::getContainer()->get(TransitStopImporter::class);
        \assert($importer instanceof TransitStopImporter);

        return $importer;
    }

    private function connection(): Connection
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);

        return $entityManager->getConnection();
    }

    private function categoryCode(string $externalId): string
    {
        $code = $this->connection()->fetchOne('SELECT c.code FROM poi p JOIN poi_category c ON c.id = p.category_id WHERE p.external_id = :id', ['id' => $externalId]);
        \assert(\is_string($code));

        return $code;
    }

    private function categoryCount(string $code): int
    {
        $count = $this->connection()->fetchOne('SELECT COUNT(*) FROM poi_category WHERE code = :code', ['code' => $code]);
        \assert(is_numeric($count));

        return (int) $count;
    }

    private function poiCount(): int
    {
        $count = $this->connection()->fetchOne('SELECT COUNT(*) FROM poi');
        \assert(is_numeric($count));

        return (int) $count;
    }

    private function writeTempFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'transit-stop-importer-test-');
        \assert(false !== $path);
        file_put_contents($path, $content);

        return $path;
    }
}
