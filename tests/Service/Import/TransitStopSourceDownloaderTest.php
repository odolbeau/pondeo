<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Service\Import\TransitStopSourceDownloader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TransitStopSourceDownloaderTest extends TestCase
{
    private string $dir;

    /** @var list<string> the `cql_filter` of every request received */
    private array $filters = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/transit-stop-downloader-test-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->dir.'/*') ?: []);
        rmdir($this->dir);
    }

    public function testItWritesOneRowPerStationAtTheCentreOfItsPolygon(): void
    {
        $this->download(
            tram: [$this->feature('EQ1', 'Gare', '44162', $this->square(-1.6, 47.2))],
            metro: [$this->feature('EQ2', 'Bellecour', '69123', $this->square(4.8, 45.7))],
        );

        self::assertSame(["nature = 'Station de tramway' AND etat_de_l_objet = 'En service'", 'nature = \'Station de métro\' AND etat_de_l_objet = \'En service\''], $this->filters);
        self::assertSame([
            'EQ1,Gare,tram,47.2000800,-1.5999200,44162',
            'EQ2,Bellecour,metro,45.7000800,4.8000800,69123',
        ], $this->lines());
    }

    public function testThePlatformsOfOneStationAreFoldedIntoOneRow(): void
    {
        $this->download(
            tram: [
                $this->feature('EQ1', 'Gare', '44162', $this->square(-1.6, 47.2)),
                // The other direction, 20 m away.
                $this->feature('EQ2', 'GARE', '44162', $this->square(-1.6, 47.2002)),
                // Same name, same commune, but 2 km away: another station.
                $this->feature('EQ3', 'Gare', '44162', $this->square(-1.6, 47.22)),
                // Same name 20 m away, but in another commune: another station.
                $this->feature('EQ4', 'Gare', '44109', $this->square(-1.6, 47.2004)),
            ],
            metro: [],
        );

        self::assertSame([
            'EQ1,Gare,tram,47.2001800,-1.5999200,44162',
            'EQ3,Gare,tram,47.2200800,-1.5999200,44162',
            'EQ4,Gare,tram,47.2004800,-1.5999200,44109',
        ], $this->lines());
    }

    public function testAStopWithoutNameOrPositionIsLeftOut(): void
    {
        $this->download(
            tram: [
                $this->feature('EQ1', null, '44162', $this->square(-1.6, 47.2)),
                $this->feature('EQ2', 'Sans position', '44162', null),
                $this->feature('EQ3', 'Gare', '44162', $this->square(-1.6, 47.2)),
            ],
            metro: [],
        );

        self::assertSame(['EQ3,Gare,tram,47.2000800,-1.5999200,44162'], $this->lines());
    }

    /**
     * @param list<array<string, mixed>> $tram
     * @param list<array<string, mixed>> $metro
     */
    private function download(array $tram, array $metro): void
    {
        $responses = [$tram, $metro];
        $client = new MockHttpClient(function (string $method, string $url) use (&$responses): MockResponse {
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            \assert(\is_string($query['cql_filter']));
            $this->filters[] = $query['cql_filter'];

            return new MockResponse(json_encode(['type' => 'FeatureCollection', 'features' => array_shift($responses)], \JSON_THROW_ON_ERROR));
        });

        new TransitStopSourceDownloader($client)->download($this->dir, static function (string $message): void {
        });
    }

    /**
     * @param list<list<array{float, float}>>|null $polygon
     *
     * @return array<string, mixed>
     */
    private function feature(string $id, ?string $name, string $commune, ?array $polygon): array
    {
        return [
            'type' => 'Feature',
            'geometry' => null === $polygon ? null : ['type' => 'MultiPolygon', 'coordinates' => [$polygon]],
            'properties' => ['cleabs' => $id, 'toponyme' => $name, 'insee_commune' => $commune],
        ];
    }

    /**
     * A 0.0002° square; its closing vertex counts, so the mean sits 0.00008°
     * from the corner on both axes.
     *
     * @return list<list<array{float, float}>>
     */
    private function square(float $lon, float $lat): array
    {
        return [[[$lon, $lat], [$lon + 0.0002, $lat], [$lon + 0.0002, $lat + 0.0002], [$lon, $lat + 0.0002], [$lon, $lat]]];
    }

    /**
     * @return list<string>
     */
    private function lines(): array
    {
        $lines = file($this->dir.'/arrets-tram-metro.csv', \FILE_IGNORE_NEW_LINES) ?: [];
        self::assertSame('id,name,mode,lat,lon,code_commune', array_shift($lines));

        return $lines;
    }
}
