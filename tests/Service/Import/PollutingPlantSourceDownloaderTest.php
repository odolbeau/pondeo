<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Service\Import\GeorisquesFeatureDownloader;
use App\Service\Import\PoiSourceFileWriter;
use App\Service\Import\PollutingPlantSourceDownloader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PollutingPlantSourceDownloaderTest extends TestCase
{
    public function testWritesTheEstablishmentsWithTheirPostalCode(): void
    {
        $httpClient = new MockHttpClient(new MockResponse(json_encode(['features' => [
            ['properties' => ['identifiant' => '0002', 'nom' => ' COVED ', 'cp' => '26780'], 'geometry' => ['type' => 'Point', 'coordinates' => [4.75, 44.45]]],
            ['properties' => ['identifiant' => '0001', 'nom' => 'SARL JEAN CARTON', 'cp' => '59380'], 'geometry' => ['type' => 'Point', 'coordinates' => [2.49, 50.97]]],
        ]], \JSON_THROW_ON_ERROR)));

        $dir = sys_get_temp_dir().'/'.uniqid('polluting-plants-test-', true);
        mkdir($dir);

        new PollutingPlantSourceDownloader(new GeorisquesFeatureDownloader($httpClient), new PoiSourceFileWriter())->download($dir, static function (string $message): void {});

        self::assertSame(<<<'CSV'
            id,name,lat,lon,address,activity
            0001,"SARL JEAN CARTON",50.97,2.49,59380,
            0002,COVED,44.45,4.75,26780,

            CSV, (string) file_get_contents($dir.'/etablissements-pollueurs.csv'));

        unlink($dir.'/etablissements-pollueurs.csv');
        rmdir($dir);
    }
}
