<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Service\Import\SevesoSiteSourceDownloader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SevesoSiteSourceDownloaderTest extends TestCase
{
    public function testKeepsTheSitesInOperationWithTheirLevelActivityAndAddress(): void
    {
        $httpClient = new MockHttpClient(new MockResponse(json_encode(['features' => [
            $this->feature('0002', 'Dépôt de gaz', 'En exploitation avec titre', 'Seveso seuil bas', '52 - Entreposage et services auxiliaires des transports', 'Zone portuaire', '56000', 'Vannes', [-2.75, 47.65]),
            $this->feature('0001', ' Raffinerie ', 'En exploitation sans titre', 'Seveso seuil haut', '19 - Cokéfaction et raffinage', '', '59140', 'Dunkerque', [2.33, 51.04]),
            // On its way out: no longer a hazard, left out.
            $this->feature('0003', 'Usine fermée', "En fin d'exploitation", 'Seveso seuil haut', '20 - Industrie chimique', 'Rue X', '35000', 'Rennes', [-1.68, 48.11]),
            // No activity given by the source.
            $this->feature('0004', 'Site sans activité', 'En exploitation avec titre', 'Seveso seuil bas', '', 'Rue Y', '35000', 'Rennes', [-1.69, 48.12]),
        ]], \JSON_THROW_ON_ERROR)));

        $dir = sys_get_temp_dir().'/'.uniqid('seveso-downloader-test-', true);
        mkdir($dir);

        new SevesoSiteSourceDownloader($httpClient)->download($dir, static function (string $message): void {});

        // Sorted by id; latitude first, as the other vendored point files.
        self::assertSame(<<<'CSV'
            id,name,lat,lon,level,activity,address
            0001,Raffinerie,51.04,2.33,high,"Cokéfaction et raffinage","59140 Dunkerque"
            0002,"Dépôt de gaz",47.65,-2.75,low,"Entreposage et services auxiliaires des transports","Zone portuaire, 56000 Vannes"
            0004,"Site sans activité",48.12,-1.69,low,,"Rue Y, 35000 Rennes"

            CSV, (string) file_get_contents($dir.'/sites-seveso.csv'));

        unlink($dir.'/sites-seveso.csv');
        rmdir($dir);
    }

    public function testRejectsAnUnknownSevesoType(): void
    {
        $httpClient = new MockHttpClient(new MockResponse(json_encode(['features' => [
            $this->feature('0001', 'Site', 'En exploitation avec titre', 'Seveso seuil moyen', '20 - Industrie chimique', 'Rue X', '35000', 'Rennes', [-1.68, 48.11]),
        ]], \JSON_THROW_ON_ERROR)));

        $dir = sys_get_temp_dir();

        $this->expectException(\RuntimeException::class);

        new SevesoSiteSourceDownloader($httpClient)->download($dir, static function (string $message): void {});
    }

    /**
     * @param array{float, float} $coordinates longitude first, as GeoJSON
     *
     * @return array<string, mixed>
     */
    private function feature(string $id, string $name, string $status, string $type, string $activity, string $street, string $postalCode, string $city, array $coordinates): array
    {
        return [
            'type' => 'Feature',
            'properties' => [
                'identifier' => $id,
                'name' => $name,
                'streetname' => $street,
                'postalcode' => $postalCode,
                'city' => $city,
                'status' => $status,
                'activity' => $activity,
                'type' => $type,
            ],
            'geometry' => ['type' => 'Point', 'coordinates' => $coordinates],
        ];
    }
}
