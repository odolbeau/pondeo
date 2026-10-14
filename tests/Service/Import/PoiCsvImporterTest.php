<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Factory\UniteUrbaineFactory;
use App\Service\Import\PoiCsvImporter;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class PoiCsvImporterTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private const string HEADER = "id,name,lat,lon,address,activity\n";

    public function testImportCreatesThePoisInTheGivenCategoryThenIsIdempotent(): void
    {
        $unit = UniteUrbaineFactory::createOne(['inseeCode' => '56501', 'boundary' => 'SRID=4326;MULTIPOLYGON(((-3 47,-3 48,-2 48,-2 47,-3 47)))']);
        $path = $this->writeTempFile(self::HEADER
            .'M1,Musée de la mer,47.5,-2.5,"Quai, 56000 Vannes","Histoire, Beaux-arts"'."\n"
            // Outside every unité urbaine and without details: kept, attached to none.
            .'M2,Musée rural,49.4,6.2,,'."\n");

        self::assertSame(2, $this->importer()->import($path, 'museum'));
        self::assertSame(2, $this->poiCount());

        // Longitude first in EWKT.
        self::assertSame('POINT(-2.5 47.5)', $this->connection()->fetchOne("SELECT ST_AsText(location::geometry) FROM poi WHERE external_id = 'M1'"));
        self::assertSame('museum', $this->connection()->fetchOne("SELECT c.code FROM poi p JOIN poi_category c ON c.id = p.category_id WHERE p.external_id = 'M1'"));
        self::assertSame('Quai, 56000 Vannes', $this->connection()->fetchOne("SELECT address FROM poi WHERE external_id = 'M1'"));
        $attributes = $this->connection()->fetchOne("SELECT attributes FROM poi WHERE external_id = 'M1'");
        \assert(\is_string($attributes));
        self::assertJsonStringEqualsJsonString('{"activity": "Histoire, Beaux-arts"}', $attributes);
        self::assertNull($this->connection()->fetchOne("SELECT attributes FROM poi WHERE external_id = 'M2'"));
        self::assertSame($unit->getId()->toRfc4122(), $this->connection()->fetchOne("SELECT unite_urbaine_id FROM poi WHERE external_id = 'M1'"));
        self::assertNull($this->connection()->fetchOne("SELECT unite_urbaine_id FROM poi WHERE external_id = 'M2'"));

        self::assertSame(2, $this->importer()->import($path, 'museum'));
        self::assertSame(2, $this->poiCount());

        unlink($path);
    }

    public function testReimportUpdatesInPlaceAndDropsThePoisTheSourceNoLongerListsOfThatCategoryOnly(): void
    {
        $firstPath = $this->writeTempFile(self::HEADER.'A,Ancien nom,47.0,-2.0,,'."\n".'B,Fermé,48.0,-1.0,,'."\n");
        $secondPath = $this->writeTempFile(self::HEADER.'A,Nouveau nom,47.5,-2.5,,'."\n");
        $otherPath = $this->writeTempFile(self::HEADER.'X,Autre catégorie,47.5,-2.5,,'."\n");

        $this->importer()->import($firstPath, 'museum');
        $this->importer()->import($otherPath, 'polluting_plant');
        $originalId = $this->connection()->fetchOne("SELECT id FROM poi WHERE external_id = 'A'");
        $this->importer()->import($secondPath, 'museum');

        self::assertSame(2, $this->poiCount());
        self::assertSame($originalId, $this->connection()->fetchOne("SELECT id FROM poi WHERE external_id = 'A'"));
        self::assertSame('Nouveau nom', $this->connection()->fetchOne("SELECT name FROM poi WHERE external_id = 'A'"));
        self::assertFalse($this->connection()->fetchOne("SELECT id FROM poi WHERE external_id = 'B'"));
        self::assertNotFalse($this->connection()->fetchOne("SELECT id FROM poi WHERE external_id = 'X'"));

        unlink($firstPath);
        unlink($secondPath);
        unlink($otherPath);
    }

    private function importer(): PoiCsvImporter
    {
        $importer = self::getContainer()->get(PoiCsvImporter::class);
        \assert($importer instanceof PoiCsvImporter);

        return $importer;
    }

    private function connection(): Connection
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);

        return $entityManager->getConnection();
    }

    private function poiCount(): int
    {
        $count = $this->connection()->fetchOne('SELECT COUNT(*) FROM poi');
        \assert(is_numeric($count));

        return (int) $count;
    }

    private function writeTempFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'poi-csv-importer-test-');
        \assert(false !== $path);
        file_put_contents($path, $content);

        return $path;
    }
}
