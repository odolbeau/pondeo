<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Factory\UniteUrbaineFactory;
use App\Service\Import\CommercesSourceDownloader;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class CommercesSourceDownloaderTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function testDownloadKeepsTheUnitesUrbaineOfTheProjectAndFollowsThePages(): void
    {
        UniteUrbaineFactory::createOne(['inseeCode' => '56501']);

        $requests = [];
        $httpClient = new MockHttpClient(static function (string $method, string $url) use (&$requests): MockResponse {
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            $type = \is_string($query['FACILITY_TYPE'] ?? null) ? $query['FACILITY_TYPE'] : '';
            $page = \is_string($query['page'] ?? null) ? $query['page'] : '';
            $requests[] = $type.'#'.$page;

            $observations = [];
            if ('B207' === $type && '1' === $page) {
                $observations = [
                    self::observation('2026-UU2020-56501', '2015', 40.0),
                    self::observation('2026-UU2020-56501', '2025', 31.0),
                    self::observation('2026-UU2020-99999', '2025', 7.0), // Out of scope.
                    self::observation('2026-AAV2020-056', '2025', 90.0), // Another zoning.
                ];
            } elseif ('B207' === $type) {
                $observations = [['dimensions' => ['GEO' => '2026-UU2020-56501', 'TIME_PERIOD' => '2020'], 'measures' => ['OBS_VALUE_NIVEAU' => []]]]; // Statistical secrecy.
            }

            return new MockResponse((string) json_encode(['observations' => $observations, 'paging' => ['isLast' => 'B207' !== $type || '2' === $page]]));
        });

        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        $dir = sys_get_temp_dir().'/commerces-downloader-test-'.bin2hex(random_bytes(4));
        mkdir($dir);

        new CommercesSourceDownloader($httpClient, $connection, throttleMicroseconds: 0)->download($dir, static function (string $line): void {});

        self::assertSame("code_unite_urbaine,year,type,count\n56501,2015,B207,40\n56501,2025,B207,31\n", file_get_contents($dir.'/commerces-bpe.csv'));
        self::assertContains('B207#2', $requests);

        unlink($dir.'/commerces-bpe.csv');
        rmdir($dir);
    }

    /**
     * @return array{dimensions: array{GEO: string, TIME_PERIOD: string}, measures: array{OBS_VALUE_NIVEAU: array{value: float}}}
     */
    private static function observation(string $geo, string $year, float $value): array
    {
        return ['dimensions' => ['GEO' => $geo, 'TIME_PERIOD' => $year], 'measures' => ['OBS_VALUE_NIVEAU' => ['value' => $value]]];
    }
}
