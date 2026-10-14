<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Factory\UniteUrbaineFactory;
use App\Service\Import\HospitalImporter;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class HospitalImporterTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private const string HEADER = "finess,name,type,lat,lon,address,emergency,emergency_pediatric\n";

    public function testImportCreatesHospitalsAndEmergencyDepartmentsThenIsIdempotent(): void
    {
        $unit = UniteUrbaineFactory::createOne(['inseeCode' => '56501', 'boundary' => 'SRID=4326;MULTIPOLYGON(((-3 47,-3 48,-2 48,-2 47,-3 47)))']);
        $path = $this->writeTempFile(self::HEADER
            .'560000001,CH DE VANNES,ch,47.5,-2.5,"1 RUE DE L\'HOPITAL, 56000 VANNES",1,1'."\n"
            .'560000002,Clinique du Golfe,clinic,47.6,-2.4,"2 AVENUE DU GOLFE, 56000 VANNES",0,0'."\n"
            // Outside every unité urbaine: kept, attached to none.
            .'220000001,CH DE LANNION,ch,48.5,-3.5,,1,0'."\n");

        self::assertSame(['hospitals' => 3, 'emergencies' => 2], $this->importer()->import($path));
        self::assertSame(5, $this->poiCount());

        // Longitude first in EWKT.
        self::assertSame('POINT(-2.5 47.5)', $this->poiValue('ST_AsText(poi.location::geometry)', '560000001', 'hospital_general'));
        self::assertSame("1 RUE DE L'HOPITAL, 56000 VANNES", $this->poiValue('poi.address', '560000001', 'hospital_general'));
        self::assertNull($this->poiValue('poi.address', '220000001', 'hospital_general'));
        // The emergency department is a POI of its own, in its own category.
        self::assertSame('{"pediatric": true}', $this->poiValue('poi.attributes', '560000001', 'hospital_emergency'));
        self::assertNull($this->poiValue('poi.attributes', '220000001', 'hospital_emergency'));
        self::assertNull($this->poiValue('poi.id', '560000002', 'hospital_emergency'));
        // Each type is a child of the parent category.
        self::assertSame('hospital', $this->scalar("SELECT parent.code FROM poi_category child INNER JOIN poi_category parent ON parent.id = child.parent_id WHERE child.code = 'hospital_clinic'"));
        self::assertSame($unit->getId()->toRfc4122(), $this->poiValue('poi.unite_urbaine_id', '560000001', 'hospital_emergency'));
        self::assertNull($this->poiValue('poi.unite_urbaine_id', '220000001', 'hospital_general'));

        self::assertSame(['hospitals' => 3, 'emergencies' => 2], $this->importer()->import($path));
        self::assertSame(5, $this->poiCount());

        unlink($path);
    }

    public function testReimportUpdatesInPlaceMovesAChangedTypeAndDropsWhatTheSourceNoLongerLists(): void
    {
        $firstPath = $this->writeTempFile(self::HEADER
            .'a,Ancien nom,ch,47.0,-2.0,Vannes,1,0'."\n"
            .'b,Disparu,clinic,48.0,-1.0,Auray,1,0'."\n");
        $secondPath = $this->writeTempFile(self::HEADER
            .'a,Nouveau nom,chu,47.5,-2.5,Vannes,0,0'."\n");

        $this->importer()->import($firstPath);
        $originalId = $this->poiValue('poi.id', 'a', 'hospital_general');
        $this->importer()->import($secondPath);

        self::assertSame(1, $this->poiCount());
        self::assertSame($originalId, $this->scalar("SELECT id FROM poi WHERE external_id = 'a'"));
        self::assertSame('Nouveau nom', $this->scalar("SELECT name FROM poi WHERE external_id = 'a'"));
        self::assertSame('hospital_university', $this->scalar("SELECT category.code FROM poi INNER JOIN poi_category category ON category.id = poi.category_id WHERE poi.external_id = 'a'"));

        unlink($firstPath);
        unlink($secondPath);
    }

    private function importer(): HospitalImporter
    {
        $importer = self::getContainer()->get(HospitalImporter::class);
        \assert($importer instanceof HospitalImporter);

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

    private function poiValue(string $expression, string $externalId, string $categoryCode): mixed
    {
        $value = $this->connection()->fetchOne("SELECT {$expression} FROM poi INNER JOIN poi_category category ON category.id = poi.category_id WHERE poi.external_id = :id AND category.code = :code", ['id' => $externalId, 'code' => $categoryCode]);

        return false === $value ? null : $value;
    }

    private function poiCount(): int
    {
        $count = $this->connection()->fetchOne('SELECT COUNT(*) FROM poi');
        \assert(is_numeric($count));

        return (int) $count;
    }

    private function writeTempFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'hospital-importer-test-');
        \assert(false !== $path);
        file_put_contents($path, $content);

        return $path;
    }
}
