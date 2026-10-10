<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Factory\UniteUrbaineFactory;
use App\Service\Import\StationImporter;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class StationImporterTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private const string HEADER = "id_gare,name,lat,lon,code_commune,code_uic\n";

    public function testImportCreatesStationsWithTheirLocationAndUniteUrbaineThenIsIdempotent(): void
    {
        $vannes = UniteUrbaineFactory::createOne(['inseeCode' => '56501', 'boundary' => 'SRID=4326;MULTIPOLYGON(((-3 47,-3 48,-2 48,-2 47,-3 47)))']);
        $path = $this->writeTempFile(self::HEADER
            .'id-vannes,Vannes,47.665169,-2.75245,56260,87476606'."\n"
            // Outside every unité urbaine: kept, attached to none.
            .'id-rural,Abancourt,49.6852237,1.7743058,60001,87313759'."\n");

        self::assertSame(2, $this->importer()->import($path));
        self::assertSame(2, $this->poiCount());

        // Longitude first in EWKT: a swapped pair would put Vannes in the Indian Ocean.
        self::assertSame('POINT(-2.75245 47.665169)', $this->connection()->fetchOne("SELECT ST_AsText(location::geometry) FROM poi WHERE external_id = 'id-vannes'"));
        self::assertSame($vannes->getId()->toRfc4122(), $this->connection()->fetchOne("SELECT unite_urbaine_id FROM poi WHERE external_id = 'id-vannes'"));
        self::assertNull($this->connection()->fetchOne("SELECT unite_urbaine_id FROM poi WHERE external_id = 'id-rural'"));

        self::assertSame(2, $this->importer()->import($path));
        self::assertSame(2, $this->poiCount());

        unlink($path);
    }

    public function testReimportUpdatesInPlaceAndDropsStationsTheSourceNoLongerLists(): void
    {
        $firstPath = $this->writeTempFile(self::HEADER
            .'id-a,Ancien nom,47.0,-2.0,56001,1'."\n"
            .'id-b,Disparue,48.0,-1.0,56002,2'."\n");
        $secondPath = $this->writeTempFile(self::HEADER
            .'id-a,Nouveau nom,47.5,-2.5,56001,1'."\n");

        $this->importer()->import($firstPath);
        $originalId = $this->connection()->fetchOne("SELECT id FROM poi WHERE external_id = 'id-a'");
        $this->importer()->import($secondPath);

        self::assertSame(1, $this->poiCount());
        self::assertSame($originalId, $this->connection()->fetchOne("SELECT id FROM poi WHERE external_id = 'id-a'"));
        self::assertSame('Nouveau nom', $this->connection()->fetchOne("SELECT name FROM poi WHERE external_id = 'id-a'"));
        self::assertSame('POINT(-2.5 47.5)', $this->connection()->fetchOne("SELECT ST_AsText(location::geometry) FROM poi WHERE external_id = 'id-a'"));

        unlink($firstPath);
        unlink($secondPath);
    }

    private function importer(): StationImporter
    {
        $importer = self::getContainer()->get(StationImporter::class);
        \assert($importer instanceof StationImporter);

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
        $path = tempnam(sys_get_temp_dir(), 'station-importer-test-');
        \assert(false !== $path);
        file_put_contents($path, $content);

        return $path;
    }
}
