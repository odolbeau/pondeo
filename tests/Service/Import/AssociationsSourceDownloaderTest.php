<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Service\Import\AssociationsSourceDownloader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AssociationsSourceDownloaderTest extends TestCase
{
    private const string HEADER = 'id;position;date_decla;objet_social1;adrs_codeinsee';

    public function testCountsActiveAssociationsPerCommuneAndCategoryFromTheLatestExtract(): void
    {
        $zipPath = $this->createArchive([
            'rna_waldec_20261001_dpt_35.csv' => [
                // Never amended since 2005: declared, not active.
                '"W1";"A";"2005-12-09";"011035";"35238"',
                '"W2";"A";"2021-03-01";"011035";"35238"',
                '"W3";"A";"2017-01-01";"006030";"35238"',
                // Politics belongs to no family: only in the totals.
                '"W4";"A";"2024-05-02";"001005";"35238"',
                '"W5";"A";"2024-05-02";"017010";"35051"',
                // Dissolved, or without a commune: not counted.
                '"W6";"D";"2024-05-02";"011035";"35238"',
                '"W7";"A";"2024-05-02";"011035";""',
                '"W8";"A";"2020-01-01";"024010";"35051"',
            ],
            // The local-law departments are skipped.
            'rna_waldec_20261001_dpt_67.csv' => ['"W9";"A";"2024-05-02";"011035";"67482"'],
        ]);

        $requests = [];
        $httpClient = new MockHttpClient(static function (string $method, string $url) use (&$requests, $zipPath): MockResponse {
            $requests[] = $url;
            if (str_contains($url, 'data.gouv.fr')) {
                return new MockResponse(json_encode(['resources' => [
                    ['title' => 'rna_import_20261001.zip', 'url' => 'https://example.test/rna_import_20261001.zip'],
                    ['title' => 'rna_waldec_20260901.zip', 'url' => 'https://example.test/rna_waldec_20260901.zip'],
                    ['title' => 'rna_waldec_20261001.zip', 'url' => 'https://example.test/rna_waldec_20261001.zip'],
                ]], \JSON_THROW_ON_ERROR));
            }

            return new MockResponse((string) file_get_contents($zipPath));
        });

        $dir = sys_get_temp_dir().'/'.uniqid('associations-downloader-test-', true);
        mkdir($dir);

        new AssociationsSourceDownloader($httpClient)->download($dir, static function (string $message): void {});

        self::assertSame('https://example.test/rna_waldec_20261001.zip', $requests[1]);
        self::assertSame(<<<'CSV'
            code_insee,year,category,count
            35051,2026,active,2
            35051,2026,all,2
            35051,2026,environnement,1
            35051,2026,sante,1
            35238,2026,active,3
            35238,2026,all,4
            35238,2026,culture,1
            35238,2026,sport,1

            CSV, file_get_contents($dir.'/associations-rna.csv'));

        unlink($zipPath);
        unlink($dir.'/associations-rna.csv');
        rmdir($dir);
    }

    /**
     * @param array<string, list<string>> $files rows (without the header) per file name
     */
    private function createArchive(array $files): string
    {
        $path = tempnam(sys_get_temp_dir(), 'rna-test-');
        \assert(false !== $path);

        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        foreach ($files as $name => $rows) {
            $zip->addFromString($name, "\u{FEFF}".self::HEADER."\r\n".implode("\n", $rows)."\n");
        }

        $zip->close();

        return $path;
    }
}
