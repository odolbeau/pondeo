<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Factory\UniteUrbaineFactory;
use App\Service\Import\ClimateNormalsCalculator;
use App\Service\Import\ClimateSourceDownloader;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class ClimateSourceDownloaderTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function testUnitsOfTheSameGridCellShareOneLocationAndDoneUnitsAreSkipped(): void
    {
        // The first two centroids fall in the same 0.1° cell.
        $this->createUnit('13701', 43.30, 5.40);
        $this->createUnit('13702', 43.31, 5.41);
        $this->createUnit('29701', 48.40, -4.50);

        $dir = sys_get_temp_dir().'/'.uniqid('climate-downloader-test-', true);
        mkdir($dir);

        $requests = new \ArrayObject();
        $httpClient = new MockHttpClient(static function (string $method, string $url) use ($requests): MockResponse {
            $requests->append($url);
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            $count = substr_count(\is_string($query['latitude'] ?? null) ? $query['latitude'] : '', ',') + 1;
            $day = ['time' => ['2020-01-10'], ...array_fill_keys(ClimateNormalsCalculator::DAILY_VARIABLES, [10.0])];

            return new MockResponse(json_encode(array_fill(0, $count, ['daily' => $day]), \JSON_THROW_ON_ERROR));
        });

        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        $archive = $dir.'/archive';
        $downloader = new ClimateSourceDownloader($connection, new ClimateNormalsCalculator(), $httpClient, 'secret-key', $archive, 4, 0);

        self::assertSame(3, $downloader->download($dir, static function (string $message): void {}));
        self::assertCount(1, $requests);
        // Two distinct cells, not three locations; the commercial host, with the key.
        $url = (string) $requests->offsetGet(0);
        self::assertStringContainsString('latitude=43.3%2C48.4', $url);
        self::assertStringStartsWith('https://customer-archive-api.open-meteo.com/', $url);
        self::assertStringContainsString('apikey=secret-key', $url);

        // One archive file per cell, and the calls are recorded.
        self::assertCount(2, glob($archive.'/normales/*.json.gz') ?: []);
        self::assertEqualsWithDelta(2 * ClimateSourceDownloader::callsPerLocation(), $downloader->spentCalls(), 1.0);

        $csv = (string) file_get_contents($dir.'/climat-normales.csv');
        self::assertStringStartsWith("code_unite_urbaine,year,measure,value\n13701,2020,temperature_moyenne,10\n", $csv);
        self::assertStringContainsString("\n13702,2020,temperature_moyenne,10\n", $csv);
        self::assertStringContainsString("\n29701,2020,jours_plus_30,0\n", $csv);

        // Re-running derives again from the archive, without any call.
        unlink($dir.'/climat-normales.csv');
        self::assertSame(3, $downloader->download($dir, static function (string $message): void {}));
        self::assertCount(1, $requests);
        self::assertSame($csv, file_get_contents($dir.'/climat-normales.csv'));

        // A budget below the projection stops before any call.
        array_map(unlink(...), glob($archive.'/normales/*.json.gz') ?: []);
        $downloader->download($dir, static function (string $message): void {}, 1000);
        self::assertCount(1, $requests);

        array_map(unlink(...), glob($archive.'/normales/*') ?: []);
        rmdir($archive.'/normales');
        rmdir($archive);
        unlink($dir.'/climat-normales.csv');
        rmdir($dir);
    }

    private function createUnit(string $code, float $lat, float $lon): void
    {
        UniteUrbaineFactory::createOne(['inseeCode' => $code]);

        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        $connection->executeStatement(
            // The centroid is generated from the boundary: a small square around the point.
            'UPDATE unite_urbaine SET boundary = ST_Multi(ST_MakeEnvelope(:lon - 0.001, :lat - 0.001, :lon + 0.001, :lat + 0.001, 4326)) WHERE insee_code = :code',
            ['lon' => $lon, 'lat' => $lat, 'code' => $code],
        );
    }
}
