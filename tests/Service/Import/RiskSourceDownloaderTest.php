<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Service\Import\CsvReader;
use App\Service\Import\RiskSourceDownloader;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class RiskSourceDownloaderTest extends KernelTestCase
{
    public function testFetchRowsDerivesOneValuePerMeasure(): void
    {
        $downloader = $this->downloader($this->api([
            'gaspar/catnat' => ['total_pages' => 1, 'data' => [
                ['date_publication_jo' => '10/08/2014'], // older than 20 years at the mock date: not counted
                ['date_publication_jo' => '07/12/2016'],
                ['date_publication_jo' => '01/03/2024'],
                ['date_publication_jo' => null],
            ]],
            'gaspar/risques' => ['data' => [['risques_detail' => [['num_risque' => '11'], ['num_risque' => '12']]]]],
            'zonage_sismique' => ['data' => [['code_zone' => '4']]],
            'radon' => ['data' => [['classe_potentiel' => '3']]],
            'rga' => ['codeExposition' => '2'],
        ]), '2035-01-01');

        self::assertSame([
            'catnat' => 2,
            'inondation' => 100,
            'feu_foret' => 0,
            'zone_sismique' => 4,
            'radon_fort' => 100,
            'argiles' => 2,
        ], $downloader->fetchRows('06088', [7.26, 43.7]));
    }

    public function testFetchRowsFollowsCatnatPagesAndSkipsMeasuresTheApiDoesNotAnswer(): void
    {
        $downloader = $this->downloader($this->api([
            'gaspar/catnat' => static fn (array $query): array => '2' === ($query['page'] ?? '1')
                ? ['total_pages' => 2, 'data' => [['date_publication_jo' => '01/03/2024']]]
                : ['total_pages' => 2, 'data' => [['date_publication_jo' => '01/03/2025'], ['date_publication_jo' => '01/03/2026']]],
            'gaspar/risques' => ['data' => []],
            'zonage_sismique' => ['data' => []],
            'radon' => ['data' => [['classe_potentiel' => '1']]],
            'rga' => null,
        ]), '2026-10-11');

        // Only what the API answered: an empty answer is not a zero.
        self::assertSame(['catnat' => 3, 'radon_fort' => 0], $downloader->fetchRows('13055', [5.37, 43.3]));
    }

    public function testDownloadResumesWithoutRefetchingCommunesAlreadyWritten(): void
    {
        $dir = sys_get_temp_dir().'/risk-downloader-test-'.bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir.'/communes-unites-urbaines.csv', "code_insee,nom_standard,dep_code,population,code_unite_urbaine,nom_unite_urbaine\n06088,Nice,06,340000,06701,Nice\n29019,Brest,29,140000,29701,Brest\n");
        // Nice is done this year; a row from another year is dropped.
        file_put_contents($dir.'/risques-communes.csv', "code_insee,year,measure,value,population\n06088,2026,zone_sismique,4,340000\n29019,2020,zone_sismique,2,140000\n");

        $requested = [];
        $downloader = $this->downloader(static function (string $method, string $url) use (&$requested): MockResponse {
            $requested[] = self::query($url)['code_insee'] ?? '';

            return new MockResponse((string) json_encode(str_contains($url, 'zonage_sismique') ? ['data' => [['code_zone' => '2']]] : ['total_pages' => 1, 'data' => []]));
        }, '2026-10-11');

        self::assertSame(1, $downloader->download($dir, static function (): void {}));
        self::assertSame(['29019'], array_values(array_unique($requested)));

        $csv = iterator_to_array(new CsvReader()->read($dir.'/risques-communes.csv'), false);
        self::assertSame(['06088', '29019'], array_values(array_unique(array_column($csv, 'code_insee'))));
        self::assertSame(['2026'], array_values(array_unique(array_column($csv, 'year'))));
        self::assertContains('140000', array_column($csv, 'population'));

        array_map(unlink(...), glob($dir.'/*') ?: []);
        rmdir($dir);
    }

    /**
     * @param array<string, array<string, mixed>|\Closure(array<int|string, string>): array<string, mixed>|null> $bodies indexed by endpoint path, null for an empty answer
     */
    private function api(array $bodies): \Closure
    {
        return static function (string $method, string $url) use ($bodies): MockResponse {
            $path = (string) parse_url($url, \PHP_URL_PATH);
            $query = self::query($url);
            foreach ($bodies as $endpoint => $body) {
                if (!str_ends_with($path, '/'.$endpoint)) {
                    continue;
                }

                if (null === $body) {
                    return new MockResponse('');
                }

                return new MockResponse((string) json_encode($body instanceof \Closure ? $body($query) : $body));
            }

            throw new \LogicException('Unexpected call to '.$url);
        };
    }

    /**
     * @return array<int|string, string>
     */
    private static function query(string $url): array
    {
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);

        return array_map(strval(...), array_filter($query, is_string(...)));
    }

    private function downloader(\Closure $responses, string $today): RiskSourceDownloader
    {
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);

        return new RiskSourceDownloader(new MockHttpClient($responses), $connection, new MockClock($today), new CsvReader(), throttleMicroseconds: 0);
    }
}
