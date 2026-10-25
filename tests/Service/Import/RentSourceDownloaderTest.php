<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Service\Import\CsvReader;
use App\Service\Import\RentSourceDownloader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class RentSourceDownloaderTest extends TestCase
{
    private const string HEADER = '"id_zone";"INSEE_C";"LIBGEO";"EPCI";"DEP";"REG";"loypredm2";"lwr.IPm2";"upr.IPm2";"TYPPRED";"nbobs_com";"nbobs_mail";"R2_adj"';

    public function testFoldsTheFilesIntoOneRowPerCommuneAndTypeWithTheArrondissementsMerged(): void
    {
        $requests = [];
        $httpClient = new MockHttpClient(static function (string $method, string $url) use (&$requests): MockResponse {
            $requests[] = $url;
            $rows = match (basename($url)) {
                // Apartments, all sizes.
                '55b34088-0964-415f-9df7-d87dd98a09be' => [
                    '"1";"38185";"Grenoble";"200040715";"38";"84";14,2345;12;16;"commune";7000;7000;0,8',
                    '"2";"05066";"La Haute-Beaume";"200067445";"05";"93";9,75769624568385;7,5;12,5;"maille";0;484;0,77',
                    // 300 ads at 30 and 100 ads at 26: (9000 + 2600) / 400 = 29.
                    '"3";"75109";"Paris 9e Arrondissement";"200054781";"75";"11";30;28;32;"commune";300;300;0,9',
                    '"4";"75120";"Paris 20e Arrondissement";"200054781";"75";"11";26;24;28;"commune";100;100;0,9',
                ],
                '129f764d-b613-44e4-952c-5ff50a8c9b73' => ['"5";"38185";"Grenoble";"200040715";"38";"84";12,5;10;15;"EPCI";40;600;0,6'],
                default => [],
            };

            return new MockResponse(implode("\r\n", [self::HEADER, ...$rows])."\r\n");
        });

        $dir = sys_get_temp_dir().'/'.uniqid('rents-downloader-test-', true);
        mkdir($dir);

        new RentSourceDownloader($httpClient, new CsvReader())->download($dir, static function (string $message): void {});

        self::assertCount(4, $requests);
        self::assertSame(<<<'CSV'
            code_insee,year,type,niveau,nb_annonces,loyer_m2
            05066,2025,appartement,maille,0,9.76
            38185,2025,appartement,commune,7000,14.23
            75056,2025,appartement,commune,400,29
            38185,2025,maison,epci,40,12.5

            CSV, file_get_contents($dir.'/loyers-annonces.csv'));

        unlink($dir.'/loyers-annonces.csv');
        rmdir($dir);
    }
}
