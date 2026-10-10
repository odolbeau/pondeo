<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Factory\PoiCategoryFactory;
use App\Factory\PoiFactory;
use App\Service\Import\StationImporter;
use App\Service\Import\StationJourneyImporter;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class StationJourneyImporterTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private const string HEADER = "id_gare,destination,reference_date,transfers,departures\n";

    public function testImportDerivesTheFiguresFromTheDepartures(): void
    {
        $this->createStations('s-direct', 's-change', 's-isolated');
        $path = $this->writeTempFile(self::HEADER
            .'s-direct,paris,2026-10-13,0,0536:175;0633:178;0736:175'."\n"
            .'s-change,paris,2026-10-13,1,0758:235;0911:202'."\n"
            // Out of reach: no transfers, no departures.
            .'s-isolated,nantes,2026-10-13,,'."\n");

        self::assertSame(['imported' => 3, 'skipped' => 0], $this->importer()->import($path));

        self::assertSame([0, 3, 175], $this->figures('s-direct', 'paris'));
        self::assertSame([1, 2, 219], $this->figures('s-change', 'paris'));
        self::assertSame([null, null, null], $this->figures('s-isolated', 'nantes'));
        self::assertSame('2026-10-13', $this->connection()->fetchOne("SELECT reference_date FROM station_journey sj INNER JOIN poi ON poi.id = sj.station_id WHERE poi.external_id = 's-direct'"));

        unlink($path);
    }

    public function testReimportUpdatesInPlaceAndDropsWhatTheFileNoLongerLists(): void
    {
        $this->createStations('s-a', 's-b');
        $first = $this->writeTempFile(self::HEADER
            .'s-a,paris,2026-10-13,0,0536:175'."\n"
            .'s-a,nantes,2026-10-13,0,0700:60'."\n"
            .'s-b,paris,2026-10-13,0,0800:100'."\n");
        $second = $this->writeTempFile(self::HEADER
            .'s-a,paris,2026-10-20,0,0536:180;0633:190'."\n");

        $this->importer()->import($first);
        self::assertSame(3, $this->journeyCount());

        $this->importer()->import($second);

        self::assertSame(1, $this->journeyCount());
        self::assertSame([0, 2, 185], $this->figures('s-a', 'paris'));
        self::assertSame('2026-10-20', $this->connection()->fetchOne('SELECT reference_date FROM station_journey'));

        unlink($first);
        unlink($second);
    }

    public function testLinesForUnknownStationsOrDestinationsAreSkipped(): void
    {
        $this->createStations('s-a');
        $path = $this->writeTempFile(self::HEADER
            .'s-a,paris,2026-10-13,0,0536:175'."\n"
            .'s-unknown,paris,2026-10-13,0,0536:175'."\n"
            .'s-a,lyon,2026-10-13,0,0536:175'."\n");

        self::assertSame(['imported' => 1, 'skipped' => 2], $this->importer()->import($path));

        unlink($path);
    }

    public function testDeletingAStationDeletesItsJourneys(): void
    {
        $this->createStations('s-a');
        $path = $this->writeTempFile(self::HEADER.'s-a,paris,2026-10-13,0,0536:175'."\n");
        $this->importer()->import($path);

        $this->connection()->executeStatement('DELETE FROM poi');

        self::assertSame(0, $this->journeyCount());

        unlink($path);
    }

    private function createStations(string ...$externalIds): void
    {
        $category = PoiCategoryFactory::createOne(['code' => StationImporter::CATEGORY_CODE, 'label' => 'Gare ferroviaire']);
        foreach ($externalIds as $externalId) {
            PoiFactory::createOne(['category' => $category, 'externalId' => $externalId]);
        }
    }

    /**
     * @return list<?int>
     */
    private function figures(string $stationExternalId, string $destination): array
    {
        $row = $this->connection()->fetchNumeric(
            'SELECT sj.transfers, sj.trains_per_day, sj.median_duration_minutes FROM station_journey sj INNER JOIN poi ON poi.id = sj.station_id WHERE poi.external_id = :id AND sj.destination = :destination',
            ['id' => $stationExternalId, 'destination' => $destination],
        );
        \assert(false !== $row);

        return array_map(static fn (mixed $value): ?int => is_numeric($value) ? (int) $value : null, $row);
    }

    private function journeyCount(): int
    {
        $count = $this->connection()->fetchOne('SELECT COUNT(*) FROM station_journey');
        \assert(is_numeric($count));

        return (int) $count;
    }

    private function importer(): StationJourneyImporter
    {
        $importer = self::getContainer()->get(StationJourneyImporter::class);
        \assert($importer instanceof StationJourneyImporter);

        return $importer;
    }

    private function connection(): Connection
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);

        return $entityManager->getConnection();
    }

    private function writeTempFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'station-journey-importer-test-');
        \assert(false !== $path);
        file_put_contents($path, $content);

        return $path;
    }
}
