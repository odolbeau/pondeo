<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Service\Import\GeorisquesFeatureDownloader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GeorisquesFeatureDownloaderTest extends TestCase
{
    public function testFollowsThePagesUntilAShortOne(): void
    {
        $starts = [];
        $httpClient = new MockHttpClient(static function (string $method, string $url) use (&$starts): MockResponse {
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            $starts[] = $query['STARTINDEX'];
            $count = '0' === $query['STARTINDEX'] ? 500 : 2;

            return new MockResponse(json_encode(['features' => array_fill(0, $count, ['properties' => [], 'geometry' => ['coordinates' => [0, 0]]])], \JSON_THROW_ON_ERROR));
        });

        $features = iterator_to_array(new GeorisquesFeatureDownloader($httpClient)->download('LAYER', 'id'), false);

        self::assertCount(502, $features);
        self::assertSame(['0', '500'], $starts);
    }
}
