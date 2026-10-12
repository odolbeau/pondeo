<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Service\Import\BoardGameAssociationSourceDownloader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class BoardGameAssociationSourceDownloaderTest extends TestCase
{
    public function testDownloadKeepsTheStructuresLocatedInFrance(): void
    {
        $dir = $this->tempDir();
        $html = '<ul id="liste">'
            .$this->item('FR', '44', 'Treillières', '7 à qui 2 jouer ?', ' 47.33', ' -1.62', 'club', '7aqui2jouer-treillieres')
            .$this->item('BE', '', 'Liège', 'Club belge', '50.6', '5.5', 'club', 'club-belge')
            // No usable position: nothing to attach to an unité urbaine.
            .$this->item('FR', '31', 'Toulouse', 'Sans position', '', '', 'ludo', 'sans-position')
            .$this->item('FR', '07', 'Aubenas', 'Le 07 Qui Prend', '44.62', '4.39', 'ludo', '7qp-aubenas')
            .'</ul>';

        $client = new MockHttpClient(new MockResponse($html));
        new BoardGameAssociationSourceDownloader($client)->download($dir, static function (): void {});

        self::assertSame([
            'id,name,type,city,department,lat,lon,url',
            '7aqui2jouer-treillieres,"7 à qui 2 jouer ?",club,Treillières,44,47.33,-1.62,https://subverti.com/fr/maps/associations/7aqui2jouer-treillieres/',
            '7qp-aubenas,"Le 07 Qui Prend",ludo,Aubenas,07,44.62,4.39,https://subverti.com/fr/maps/associations/7qp-aubenas/',
        ], file($dir.'/associations-jeux.csv', \FILE_IGNORE_NEW_LINES));

        unlink($dir.'/associations-jeux.csv');
        rmdir($dir);
    }

    public function testDownloadFailsWhenThePageHasNoStructure(): void
    {
        $dir = $this->tempDir();

        $this->expectException(\RuntimeException::class);

        try {
            new BoardGameAssociationSourceDownloader(new MockHttpClient(new MockResponse('<p>Maintenance</p>')))->download($dir, static function (): void {});
        } finally {
            rmdir($dir);
        }
    }

    private function item(string $country, string $department, string $city, string $name, string $latitude, string $longitude, string $group, string $slug): string
    {
        return \sprintf(
            '<li class="fest__item" data-dep="%s" data-country="%s" data-city="%s" data-name="%s" data-latit="%s" data-longit="%s" data-group="%s"><a class="fest__link" href="../../../fr/maps/associations/%s/">x</a></li>',
            $department,
            $country,
            htmlspecialchars($city),
            htmlspecialchars($name),
            $latitude,
            $longitude,
            $group,
            $slug,
        );
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir().'/board-game-downloader-test-'.bin2hex(random_bytes(4));
        mkdir($dir);

        return $dir;
    }
}
