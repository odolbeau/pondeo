<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Factory\UniteUrbaineFactory;
use App\Service\Import\NuclearPlantImporter;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class NuclearPlantImporterTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private const string HEADER = "id,name,lat,lon,reactors,power_mw\n";

    public function testImportCreatesPlantsWithLocationAndDetailsThenIsIdempotent(): void
    {
        $unit = UniteUrbaineFactory::createOne(['inseeCode' => '56501', 'boundary' => 'SRID=4326;MULTIPOLYGON(((-3 47,-3 48,-2 48,-2 47,-3 47)))']);
        $path = $this->writeTempFile(self::HEADER
            .'BLAYAIS (LE),Le Blayais,47.5,-2.5,4,3704'."\n"
            // Outside every unité urbaine: kept, attached to none.
            .'CATTENOM,Cattenom,49.415953,6.218271,4,5200'."\n");

        self::assertSame(2, $this->importer()->import($path));
        self::assertSame(2, $this->poiCount());

        // Longitude first in EWKT.
        self::assertSame('POINT(-2.5 47.5)', $this->connection()->fetchOne("SELECT ST_AsText(location::geometry) FROM poi WHERE external_id = 'BLAYAIS (LE)'"));
        self::assertEquals(['reactors' => 4, 'powerMw' => 3704], $this->attributes('BLAYAIS (LE)'));
        self::assertSame($unit->getId()->toRfc4122(), $this->connection()->fetchOne("SELECT unite_urbaine_id FROM poi WHERE external_id = 'BLAYAIS (LE)'"));
        self::assertNull($this->connection()->fetchOne("SELECT unite_urbaine_id FROM poi WHERE external_id = 'CATTENOM'"));

        self::assertSame(2, $this->importer()->import($path));
        self::assertSame(2, $this->poiCount());

        unlink($path);
    }

    public function testReimportUpdatesInPlaceAndDropsPlantsTheSourceNoLongerLists(): void
    {
        $firstPath = $this->writeTempFile(self::HEADER
            .'A,Ancien nom,47.0,-2.0,2,2000'."\n"
            .'B,Disparue,48.0,-1.0,2,2000'."\n");
        $secondPath = $this->writeTempFile(self::HEADER
            .'A,Nouveau nom,47.5,-2.5,3,3000'."\n");

        $this->importer()->import($firstPath);
        $originalId = $this->connection()->fetchOne("SELECT id FROM poi WHERE external_id = 'A'");
        $this->importer()->import($secondPath);

        self::assertSame(1, $this->poiCount());
        self::assertSame($originalId, $this->connection()->fetchOne("SELECT id FROM poi WHERE external_id = 'A'"));
        self::assertSame('Nouveau nom', $this->connection()->fetchOne("SELECT name FROM poi WHERE external_id = 'A'"));
        self::assertEquals(['reactors' => 3, 'powerMw' => 3000], $this->attributes('A'));

        unlink($firstPath);
        unlink($secondPath);
    }

    private function importer(): NuclearPlantImporter
    {
        $importer = self::getContainer()->get(NuclearPlantImporter::class);
        \assert($importer instanceof NuclearPlantImporter);

        return $importer;
    }

    private function connection(): Connection
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);

        return $entityManager->getConnection();
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(string $externalId): array
    {
        $json = $this->connection()->fetchOne('SELECT attributes FROM poi WHERE external_id = :id', ['id' => $externalId]);
        \assert(\is_string($json));

        /** @var array<string, mixed> $attributes */
        $attributes = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);

        return $attributes;
    }

    private function poiCount(): int
    {
        $count = $this->connection()->fetchOne('SELECT COUNT(*) FROM poi');
        \assert(is_numeric($count));

        return (int) $count;
    }

    private function writeTempFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'nuclear-plant-importer-test-');
        \assert(false !== $path);
        file_put_contents($path, $content);

        return $path;
    }
}
