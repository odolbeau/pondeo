<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Enum\Departement;
use App\Service\Import\ZoneImporter;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class ZoneImporterTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private const string HEADER = "code_insee,nom_standard,dep_code,population,code_unite_urbaine,nom_unite_urbaine\n";

    public function testImportCreatesUnitesUrbainesAndCommunesThenIsIdempotent(): void
    {
        $importer = $this->importer();
        $csvPath = $this->writeTempFile(self::HEADER
            .'56260,Vannes,56,55790,56501,Vannes'."\n"
            .'56206,Saint-Avé,56,12470,56501,Vannes'."\n"
            .'44109,Nantes,44,320732,44701,Nantes'."\n");

        self::assertSame(['unitesUrbaines' => 2, 'communes' => 3], $importer->importUnitesUrbaines($csvPath));
        self::assertSame(2, $this->rowCount('unite_urbaine'));
        self::assertSame(3, $this->rowCount('commune'));
        self::assertSame(Departement::Morbihan->value, $this->connection()->fetchOne("SELECT departement FROM unite_urbaine WHERE insee_code = '56501'"));

        $importer->importUnitesUrbaines($csvPath);
        self::assertSame(2, $this->rowCount('unite_urbaine'));
        self::assertSame(3, $this->rowCount('commune'));

        unlink($csvPath);
    }

    public function testUniteUrbaineDepartementIsTheMostPopulousCommunes(): void
    {
        $csvPath = $this->writeTempFile(self::HEADER
            .'56001,Petite,56,1000,99001,Grande ville'."\n"
            .'22001,Grosse,22,9000,99001,Grande ville'."\n");

        $this->importer()->importUnitesUrbaines($csvPath);

        self::assertSame(Departement::CotesDArmor->value, $this->connection()->fetchOne("SELECT departement FROM unite_urbaine WHERE insee_code = '99001'"));

        unlink($csvPath);
    }

    public function testReimportDropsWhatTheSourceNoLongerLists(): void
    {
        $importer = $this->importer();
        $firstPath = $this->writeTempFile(self::HEADER
            .'56260,Vannes,56,55790,56501,Vannes'."\n"
            .'44109,Nantes,44,320732,44701,Nantes'."\n");
        $secondPath = $this->writeTempFile(self::HEADER
            .'56260,Vannes,56,55790,56501,Vannes'."\n");

        $importer->importUnitesUrbaines($firstPath);
        $importer->importUnitesUrbaines($secondPath);

        self::assertSame(1, $this->rowCount('unite_urbaine'));
        self::assertSame(1, $this->rowCount('commune'));

        unlink($firstPath);
        unlink($secondPath);
    }

    public function testUniteUrbaineBoundaryIsTheUnionOfItsCommunes(): void
    {
        $importer = $this->importer();
        $csvPath = $this->writeTempFile(self::HEADER
            .'56260,Vannes,56,55790,56501,Vannes'."\n"
            .'56206,Saint-Avé,56,12470,56501,Vannes'."\n");
        $geojsonPath = $this->writeTempFile(json_encode([
            'type' => 'FeatureCollection',
            'features' => [
                ['type' => 'Feature', 'properties' => ['code' => '56260'], 'geometry' => ['type' => 'Polygon', 'coordinates' => [[[0, 0], [0, 1], [1, 1], [1, 0], [0, 0]]]]],
                ['type' => 'Feature', 'properties' => ['code' => '56206'], 'geometry' => ['type' => 'Polygon', 'coordinates' => [[[1, 0], [1, 1], [2, 1], [2, 0], [1, 0]]]]],
                ['type' => 'Feature', 'properties' => ['code' => '00000'], 'geometry' => ['type' => 'Polygon', 'coordinates' => [[[5, 5], [5, 6], [6, 6], [6, 5], [5, 5]]]]],
            ],
        ], \JSON_THROW_ON_ERROR));

        $importer->importUnitesUrbaines($csvPath);

        // The third feature belongs to no imported commune: ignored.
        self::assertSame(2, $importer->importCommuneBoundaries($geojsonPath));

        $importer->computeUniteUrbaineBoundaries();

        self::assertEqualsWithDelta(2.0, $this->fetchNumber("SELECT ST_Area(boundary) FROM unite_urbaine WHERE insee_code = '56501'"), 0.0001);
        self::assertNotNull($this->connection()->fetchOne("SELECT centroid FROM unite_urbaine WHERE insee_code = '56501'"));

        unlink($csvPath);
        unlink($geojsonPath);
    }

    private function importer(): ZoneImporter
    {
        $importer = self::getContainer()->get(ZoneImporter::class);
        \assert($importer instanceof ZoneImporter);

        return $importer;
    }

    private function connection(): Connection
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);

        return $entityManager->getConnection();
    }

    private function rowCount(string $table): int
    {
        return (int) $this->fetchNumber(\sprintf('SELECT COUNT(*) FROM %s', $table));
    }

    private function fetchNumber(string $sql): float
    {
        $value = $this->connection()->fetchOne($sql);
        \assert(is_numeric($value));

        return (float) $value;
    }

    private function writeTempFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'zone-importer-test-');
        \assert(false !== $path);
        file_put_contents($path, $content);

        return $path;
    }
}
