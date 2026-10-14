<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Factory\UniteUrbaineFactory;
use App\Service\Import\SevesoSiteImporter;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class SevesoSiteImporterTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private const string HEADER = "id,name,lat,lon,level,activity,address\n";

    public function testImportCreatesSitesByLevelThenIsIdempotent(): void
    {
        $unit = UniteUrbaineFactory::createOne(['inseeCode' => '56501', 'boundary' => 'SRID=4326;MULTIPOLYGON(((-3 47,-3 48,-2 48,-2 47,-3 47)))']);
        $path = $this->writeTempFile(self::HEADER
            .'0001,Raffinerie,47.5,-2.5,high,Cokéfaction et raffinage,"Port, 56000 Vannes"'."\n"
            // Outside every unité urbaine and without details: kept, attached to none.
            .'0002,Dépôt,49.4,6.2,low,,'."\n");

        self::assertSame(2, $this->importer()->import($path));
        self::assertSame(2, $this->poiCount());

        // Longitude first in EWKT.
        self::assertSame('POINT(-2.5 47.5)', $this->connection()->fetchOne("SELECT ST_AsText(location::geometry) FROM poi WHERE external_id = '0001'"));
        self::assertSame('seveso_high', $this->categoryCode('0001'));
        self::assertSame('seveso_low', $this->categoryCode('0002'));
        self::assertSame('Port, 56000 Vannes', $this->connection()->fetchOne("SELECT address FROM poi WHERE external_id = '0001'"));
        self::assertEquals(['activity' => 'Cokéfaction et raffinage'], $this->attributes('0001'));
        self::assertNull($this->connection()->fetchOne("SELECT attributes FROM poi WHERE external_id = '0002'"));
        self::assertSame($unit->getId()->toRfc4122(), $this->connection()->fetchOne("SELECT unite_urbaine_id FROM poi WHERE external_id = '0001'"));
        self::assertNull($this->connection()->fetchOne("SELECT unite_urbaine_id FROM poi WHERE external_id = '0002'"));

        self::assertSame(2, $this->importer()->import($path));
        self::assertSame(2, $this->poiCount());

        unlink($path);
    }

    public function testReimportUpdatesInPlaceMovesChangedLevelsAndDropsSitesTheSourceNoLongerLists(): void
    {
        $firstPath = $this->writeTempFile(self::HEADER
            .'A,Ancien nom,47.0,-2.0,low,Industrie chimique,'."\n"
            .'B,Fermé,48.0,-1.0,high,Industrie chimique,'."\n");
        $secondPath = $this->writeTempFile(self::HEADER
            .'A,Nouveau nom,47.5,-2.5,high,Métallurgie,'."\n");

        $this->importer()->import($firstPath);
        $originalId = $this->connection()->fetchOne("SELECT id FROM poi WHERE external_id = 'A'");
        $this->importer()->import($secondPath);

        self::assertSame(1, $this->poiCount());
        self::assertSame($originalId, $this->connection()->fetchOne("SELECT id FROM poi WHERE external_id = 'A'"));
        self::assertSame('Nouveau nom', $this->connection()->fetchOne("SELECT name FROM poi WHERE external_id = 'A'"));
        self::assertSame('seveso_high', $this->categoryCode('A'));
        self::assertEquals(['activity' => 'Métallurgie'], $this->attributes('A'));

        unlink($firstPath);
        unlink($secondPath);
    }

    public function testUnknownLevelIsRejected(): void
    {
        $path = $this->writeTempFile(self::HEADER.'A,Site,47.0,-2.0,medium,,'."\n");

        $this->expectException(\LogicException::class);

        try {
            $this->importer()->import($path);
        } finally {
            unlink($path);
        }
    }

    private function importer(): SevesoSiteImporter
    {
        $importer = self::getContainer()->get(SevesoSiteImporter::class);
        \assert($importer instanceof SevesoSiteImporter);

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
        $path = tempnam(sys_get_temp_dir(), 'seveso-site-importer-test-');
        \assert(false !== $path);
        file_put_contents($path, $content);

        return $path;
    }
}
