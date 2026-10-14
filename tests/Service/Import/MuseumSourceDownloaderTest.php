<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Service\Import\CsvReader;
use App\Service\Import\MuseumSourceDownloader;
use App\Service\Import\PoiSourceFileWriter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class MuseumSourceDownloaderTest extends TestCase
{
    public function testKeepsTheMuseumsWithCoordinatesAndTheirThemes(): void
    {
        $header = 'Identifiant|Nom_officiel|Adresse|Code_postal|Ville|Domaine_thematique|Coordonnees';
        $httpClient = new MockHttpClient(new MockResponse(implode("\n", [
            $header,
            'M2|"musée de la poterie"|"2 rue de Kuhlendorf"|"67660"|"Betschdorf"|"Arts décoratifs;Technique et industrie"|"48.900348, 7.914409"',
            // A multiline description would sit in another column: the file's quoting must hold.
            'M1|"musée des sapeurs-pompiers"|"358 avenue de Champagne"|"69009"|"Lyon"|"Histoire"|"45.790491, 4.797411"',
            // No coordinates: left out.
            'M3|"musée sans position"|"Rue X"|"35000"|"Rennes"|"Histoire"|""',
        ])."\n"));

        $dir = sys_get_temp_dir().'/'.uniqid('museums-test-', true);
        mkdir($dir);

        new MuseumSourceDownloader($httpClient, new CsvReader(), new PoiSourceFileWriter())->download($dir, static function (string $message): void {});

        self::assertSame(<<<'CSV'
            id,name,lat,lon,address,activity
            M1,"musée des sapeurs-pompiers",45.790491,4.797411,"358 avenue de Champagne, 69009 Lyon",Histoire
            M2,"musée de la poterie",48.900348,7.914409,"2 rue de Kuhlendorf, 67660 Betschdorf","Arts décoratifs, Technique et industrie"

            CSV, (string) file_get_contents($dir.'/musees.csv'));

        unlink($dir.'/musees.csv');
        rmdir($dir);
    }
}
