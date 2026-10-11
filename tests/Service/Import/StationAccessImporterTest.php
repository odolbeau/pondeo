<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Factory\PoiCategoryFactory;
use App\Factory\PoiFactory;
use App\Factory\UniteUrbaineFactory;
use App\Service\Detail\StationAccessDetail;
use App\Service\Detail\UniteUrbaineStationAccessProvider;
use App\Service\Import\DrivingRouteClient;
use App\Service\Import\StationAccessImporter;
use App\Service\Import\StationAccessSourceDownloader;
use App\Service\Import\StationImporter;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class StationAccessImporterTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private const string SQUARE = 'SRID=4326;MULTIPOLYGON(((-3 47,-3 48,-2 48,-2 47,-3 47)))';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/station-access-test-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->dir.'/*') ?: []);
        rmdir($this->dir);
        parent::tearDown();
    }

    public function testStationsInsideTheUnitCostNothingAndNeedNoRoute(): void
    {
        $unit = UniteUrbaineFactory::createOne(['inseeCode' => '56501', 'boundary' => self::SQUARE]);
        $category = PoiCategoryFactory::createOne(['code' => StationImporter::CATEGORY_CODE]);
        PoiFactory::createOne(['category' => $category, 'externalId' => 'in-unit', 'location' => 'SRID=4326;POINT(-2.5 47.5)', 'uniteUrbaine' => $unit]);

        $calls = 0;
        $written = $this->downloader(static function () use (&$calls): ResponseInterface {
            ++$calls;

            return new JsonMockResponse([]);
        })->download($this->dir, static function (): void {});

        self::assertSame(1, $written);
        self::assertSame(0, $calls);
        self::assertStringContainsString('56501,1,in-unit,0,0,', $this->csv());
    }

    public function testOutsideTheUnitTheClosestStationsAreListedFastestFirst(): void
    {
        UniteUrbaineFactory::createOne(['inseeCode' => '56501', 'boundary' => self::SQUARE]);
        $category = PoiCategoryFactory::createOne(['code' => StationImporter::CATEGORY_CODE]);
        // "near" is closer as the crow flies, "far" is faster by road; "lost" has no route.
        PoiFactory::createOne(['category' => $category, 'externalId' => 'near', 'location' => 'SRID=4326;POINT(-1.9 47.5)']);
        PoiFactory::createOne(['category' => $category, 'externalId' => 'far', 'location' => 'SRID=4326;POINT(-1.5 47.5)']);
        PoiFactory::createOne(['category' => $category, 'externalId' => 'lost', 'location' => 'SRID=4326;POINT(-1.0 47.5)']);

        $this->downloader(static fn (string $method, string $url): ResponseInterface => match (true) {
            str_contains($url, 'end=-1.900000') => new JsonMockResponse(['distance' => 9000.0, 'duration' => 1200.0]),
            str_contains($url, 'end=-1.500000') => new JsonMockResponse(['distance' => 30000.0, 'duration' => 900.0]),
            default => new JsonMockResponse(['message' => 'no Route matched'], ['http_code' => 400]),
        })->download($this->dir, static function (): void {});

        $csv = $this->csv();
        self::assertStringContainsString('56501,1,far,30000,900,', $csv);
        self::assertStringContainsString('56501,2,near,9000,1200,', $csv);
        self::assertStringNotContainsString('lost', $csv);
    }

    public function testAUnitWithoutAnyRouteIsLeftOut(): void
    {
        UniteUrbaineFactory::createOne(['inseeCode' => '56501', 'boundary' => self::SQUARE]);
        $category = PoiCategoryFactory::createOne(['code' => StationImporter::CATEGORY_CODE]);
        PoiFactory::createOne(['category' => $category, 'externalId' => 'near', 'location' => 'SRID=4326;POINT(-1.9 47.5)']);

        $written = $this->downloader(static fn (): ResponseInterface => new JsonMockResponse(['message' => 'no Route matched'], ['http_code' => 400]))
            ->download($this->dir, static function (): void {});

        self::assertSame(0, $written);
        self::assertStringNotContainsString('56501', $this->csv());
    }

    public function testImportReplacesTheLinksAndIgnoresUnknownUnitsOrStations(): void
    {
        $unit = UniteUrbaineFactory::createOne(['inseeCode' => '56501']);
        $category = PoiCategoryFactory::createOne(['code' => StationImporter::CATEGORY_CODE]);
        PoiFactory::createOne(['category' => $category, 'externalId' => 'a', 'name' => 'Vannes']);
        PoiFactory::createOne(['category' => $category, 'externalId' => 'b', 'name' => 'Auray']);

        $path = $this->dir.'/in.csv';
        file_put_contents($path, "unite_urbaine_code,position,station_id,distance_m,duration_s,period\n56501,1,a,12345,1500,2026\n56501,2,b,20000,1800,2026\n99999,1,a,1,1,2026\n56501,3,unknown,1,1,2026\n");

        $importer = $this->importer();
        self::assertSame(2, $importer->import($path));
        self::assertSame(2, $importer->import($path));
        self::assertSame(2, $this->connection()->fetchOne('SELECT COUNT(*) FROM station_access'));

        $provider = self::getContainer()->get(UniteUrbaineStationAccessProvider::class);
        \assert($provider instanceof UniteUrbaineStationAccessProvider);
        $stations = $provider->find($unit);
        self::assertSame(['Vannes', 'Auray'], array_map(static fn (StationAccessDetail $s): string => $s->name, $stations));
        self::assertSame(25, $stations[0]->minutes());
        self::assertSame(12.3, $stations[0]->kilometers());
    }

    private function downloader(callable $responses): StationAccessSourceDownloader
    {
        return new StationAccessSourceDownloader($this->connection(), new DrivingRouteClient(new MockHttpClient($responses)));
    }

    private function importer(): StationAccessImporter
    {
        $importer = self::getContainer()->get(StationAccessImporter::class);
        \assert($importer instanceof StationAccessImporter);

        return $importer;
    }

    private function csv(): string
    {
        return (string) file_get_contents($this->dir.'/'.StationAccessSourceDownloader::FILE);
    }

    private function connection(): Connection
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);

        return $entityManager->getConnection();
    }
}
