<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Service\Import\GeorisquesFeatureDownloader;
use App\Service\Import\PoiSourceFileWriter;
use App\Service\Import\PollutedSiteSourceDownloader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PollutedSiteSourceDownloaderTest extends TestCase
{
    public function testWritesTheSitesWithTheirNameAndAddress(): void
    {
        $httpClient = new MockHttpClient(new MockResponse(json_encode(['features' => [
            $this->feature('SSP2', 'Ancienne usine à gaz', '', '13 rue Davy', '93250', 'VILLEMOMBLE', '2.5', '48.9'),
            // Only the establishment is named, then nothing at all.
            $this->feature('SSP1', '', 'Etablissement X', '', '56000', 'VANNES', '-2.75', '47.65'),
            $this->feature('SSP3', ' ', '', 'Rue Y', '35000', 'RENNES', '-1.68', '48.11'),
        ]], \JSON_THROW_ON_ERROR)));

        $dir = sys_get_temp_dir().'/'.uniqid('polluted-sites-test-', true);
        mkdir($dir);

        new PollutedSiteSourceDownloader(new GeorisquesFeatureDownloader($httpClient), new PoiSourceFileWriter())->download($dir, static function (string $message): void {});

        self::assertSame(<<<'CSV'
            id,name,lat,lon,address,activity
            SSP1,"Etablissement X",47.65,-2.75,"56000 VANNES",
            SSP2,"Ancienne usine à gaz",48.9,2.5,"13 rue Davy, 93250 VILLEMOMBLE",
            SSP3,"Site pollué",48.11,-1.68,"Rue Y, 35000 RENNES",

            CSV, (string) file_get_contents($dir.'/sites-pollues.csv'));

        unlink($dir.'/sites-pollues.csv');
        rmdir($dir);
    }

    /**
     * @return array<string, mixed>
     */
    private function feature(string $id, string $site, string $establishment, string $address, string $postalCode, string $city, string $lon, string $lat): array
    {
        return [
            'type' => 'Feature',
            'properties' => [
                'code_metier' => $id,
                'nom_site' => $site,
                'nom_etablissement' => $establishment,
                'adresse' => $address,
                'code_postal' => $postalCode,
                'nom_commune' => $city,
                'x_wgs84' => $lon,
                'y_wgs84' => $lat,
            ],
            'geometry' => ['type' => 'Polygon', 'coordinates' => []],
        ];
    }
}
