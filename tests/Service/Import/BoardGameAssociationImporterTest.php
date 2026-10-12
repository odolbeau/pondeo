<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Factory\UniteUrbaineFactory;
use App\Service\Import\BoardGameAssociationImporter;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class BoardGameAssociationImporterTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private const string HEADER = "id,name,type,city,department,lat,lon,url\n";

    public function testImportAttachesAssociationsToTheirUniteUrbaineThenIsIdempotent(): void
    {
        $unit = UniteUrbaineFactory::createOne(['inseeCode' => '56501', 'boundary' => 'SRID=4326;MULTIPOLYGON(((-3 47,-3 48,-2 48,-2 47,-3 47)))']);
        $path = $this->writeTempFile(self::HEADER
            .'ludopathes-vannes,Les Ludopathes,club,Vannes,56,47.5,-2.5,https://subverti.com/fr/maps/associations/ludopathes-vannes/'."\n"
            .'cercle-des-des-auray,Le Cercle Des Dés,ludo,Auray,56,47.6,-2.4,https://subverti.com/fr/maps/associations/cercle-des-des-auray/'."\n"
            // Outside every unité urbaine: kept, attached to none.
            .'jeux-en-bois-lannion,Jeux En Bois,club,Lannion,22,48.5,-3.5,https://subverti.com/fr/maps/associations/jeux-en-bois-lannion/'."\n");

        self::assertSame(3, $this->importer()->import($path));
        self::assertSame(3, $this->poiCount());

        // Longitude first in EWKT.
        self::assertSame('POINT(-2.5 47.5)', $this->connection()->fetchOne("SELECT ST_AsText(location::geometry) FROM poi WHERE external_id = 'ludopathes-vannes'"));
        self::assertSame('Vannes', $this->connection()->fetchOne("SELECT address FROM poi WHERE external_id = 'ludopathes-vannes'"));
        self::assertSame('club', $this->connection()->fetchOne("SELECT attributes->>'type' FROM poi WHERE external_id = 'ludopathes-vannes'"));
        self::assertSame($unit->getId()->toRfc4122(), $this->connection()->fetchOne("SELECT unite_urbaine_id FROM poi WHERE external_id = 'ludopathes-vannes'"));
        self::assertNull($this->connection()->fetchOne("SELECT unite_urbaine_id FROM poi WHERE external_id = 'jeux-en-bois-lannion'"));

        self::assertSame(3, $this->importer()->import($path));
        self::assertSame(3, $this->poiCount());

        unlink($path);
    }

    public function testReimportUpdatesInPlaceAndDropsAssociationsTheSourceNoLongerLists(): void
    {
        $firstPath = $this->writeTempFile(self::HEADER
            .'A,Ancien nom,club,Vannes,56,47.0,-2.0,u'."\n"
            .'B,Disparue,club,Auray,56,48.0,-1.0,u'."\n");
        $secondPath = $this->writeTempFile(self::HEADER
            .'A,Nouveau nom,club,Vannes,56,47.5,-2.5,u'."\n");

        $this->importer()->import($firstPath);
        $originalId = $this->connection()->fetchOne("SELECT id FROM poi WHERE external_id = 'A'");
        $this->importer()->import($secondPath);

        self::assertSame(1, $this->poiCount());
        self::assertSame($originalId, $this->connection()->fetchOne("SELECT id FROM poi WHERE external_id = 'A'"));
        self::assertSame('Nouveau nom', $this->connection()->fetchOne("SELECT name FROM poi WHERE external_id = 'A'"));

        unlink($firstPath);
        unlink($secondPath);
    }

    private function importer(): BoardGameAssociationImporter
    {
        $importer = self::getContainer()->get(BoardGameAssociationImporter::class);
        \assert($importer instanceof BoardGameAssociationImporter);

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
        $path = tempnam(sys_get_temp_dir(), 'board-game-association-importer-test-');
        \assert(false !== $path);
        file_put_contents($path, $content);

        return $path;
    }
}
