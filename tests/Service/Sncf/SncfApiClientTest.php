<?php

declare(strict_types=1);

namespace App\Tests\Service\Sncf;

use App\Service\Sncf\DateOutOfBoundsException;
use App\Service\Sncf\SncfApiClient;
use App\Service\Sncf\SncfApiException;
use App\Service\Sncf\SncfAuthenticationException;
use App\Service\Sncf\UnknownStopAreaException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SncfApiClientTest extends TestCase
{
    private const string FROM = 'stop_area:SNCF:87592378';

    private const string TO = 'admin:fr:75056';

    public function testParsesJourneysAndAsksForTheWholeDayOfRailOnly(): void
    {
        $requestedUrls = [];
        $http = new MockHttpClient(static function (string $method, string $url) use (&$requestedUrls): MockResponse {
            $requestedUrls[] = urldecode($url);

            return new MockResponse((string) json_encode(['journeys' => [
                ['departure_date_time' => '20261013T053600', 'duration' => 10_500, 'nb_transfers' => 0],
                ['departure_date_time' => '20261013T063300', 'duration' => 10_680, 'nb_transfers' => 0],
            ]]));
        }, 'https://api.sncf.com/v1/coverage/sncf/');

        $journeys = $this->client($http)->journeys(self::FROM, self::TO, new \DateTimeImmutable('2026-10-13'), maxTransfers: 0);

        self::assertCount(2, $journeys);
        self::assertSame('05:36', $journeys[0]->departure->format('H:i'));
        self::assertSame(175, $journeys[0]->durationMinutes());
        self::assertSame(0, $journeys[0]->transfers);

        self::assertCount(1, $requestedUrls);
        foreach (['from=stop_area:SNCF:87592378', 'to=admin:fr:75056', 'datetime=20261013T000000', 'timeframe_duration=86400', 'max_nb_transfers=0', 'data_freshness=base_schedule', 'forbidden_uris[]=physical_mode:Coach', 'forbidden_uris[]=physical_mode:Bus'] as $expected) {
            self::assertStringContainsString($expected, $requestedUrls[0]);
        }
    }

    public function testNoCapOnTransfersLeavesTheParameterOut(): void
    {
        $requestedUrls = [];
        $http = new MockHttpClient(static function (string $method, string $url) use (&$requestedUrls): MockResponse {
            $requestedUrls[] = $url;

            return new MockResponse('{"journeys": []}');
        }, 'https://api.sncf.com/v1/coverage/sncf/');

        $this->client($http)->journeys(self::FROM, self::TO, new \DateTimeImmutable('2026-10-13'));

        self::assertStringNotContainsString('max_nb_transfers', $requestedUrls[0]);
    }

    public function testNoSolutionIsAnEmptyList(): void
    {
        $http = new MockHttpClient(new MockResponse('{"error": {"id": "no_solution", "message": "no solution found for this journey"}}'));

        self::assertSame([], $this->client($http)->journeys(self::FROM, self::TO, new \DateTimeImmutable('2026-10-13'), 0));
    }

    public function testNoRouteErrorsAreAnEmptyList(): void
    {
        foreach (['no_origin', 'no_destination', 'no_origin_nor_destination'] as $errorId) {
            $http = new MockHttpClient(new MockResponse((string) json_encode(['error' => ['id' => $errorId, 'message' => 'x']])));

            self::assertSame([], $this->client($http)->journeys(self::FROM, self::TO, new \DateTimeImmutable('2026-10-13'), 0), $errorId);
        }
    }

    public function testAnUnexpectedApiErrorStillThrows(): void
    {
        $http = new MockHttpClient(new MockResponse('{"error": {"id": "bad_format", "message": "bad datetime"}}', ['http_code' => 400]));

        $this->expectException(SncfApiException::class);

        $this->client($http)->journeys(self::FROM, self::TO, new \DateTimeImmutable('2026-10-13'));
    }

    public function testUnknownStopAreaThrows(): void
    {
        $http = new MockHttpClient(new MockResponse('{"error": {"id": "unknown_object", "message": "not valid"}}', ['http_code' => 404]));

        $this->expectException(UnknownStopAreaException::class);

        $this->client($http)->journeys(self::FROM, self::TO, new \DateTimeImmutable('2026-10-13'));
    }

    public function testDateOutsideTheProductionPeriodThrows(): void
    {
        $http = new MockHttpClient(new MockResponse('{"error": {"id": "date_out_of_bounds", "message": "date is not in data production period"}}', ['http_code' => 404]));

        $this->expectException(DateOutOfBoundsException::class);

        $this->client($http)->journeys(self::FROM, self::TO, new \DateTimeImmutable('2027-10-13'));
    }

    public function testRefusedTokenThrows(): void
    {
        $http = new MockHttpClient(new MockResponse('', ['http_code' => 401]));

        try {
            $this->client($http)->journeys(self::FROM, self::TO, new \DateTimeImmutable('2026-10-13'));
            self::fail('An exception was expected.');
        } catch (SncfAuthenticationException $e) {
            self::assertStringContainsString('refused the token', $e->getMessage());
        }
    }

    public function testEmptyTokenFailsBeforeAnyRequest(): void
    {
        $http = new MockHttpClient(static fn (): MockResponse => self::fail('No request expected.'));

        try {
            $this->client($http, token: '')->journeys(self::FROM, self::TO, new \DateTimeImmutable('2026-10-13'));
            self::fail('An exception was expected.');
        } catch (SncfAuthenticationException $e) {
            self::assertStringContainsString('SNCF_API_TOKEN', $e->getMessage());
        }
    }

    public function testQuotaRefusalPausesForRetryAfterThenResumes(): void
    {
        $clock = new MockClock('2026-10-10 10:00:00');
        $http = new MockHttpClient([
            new MockResponse('', ['http_code' => 429, 'response_headers' => ['Retry-After: 120']]),
            new MockResponse('{"journeys": [{"departure_date_time": "20261013T053600", "duration": 600, "nb_transfers": 0}]}'),
        ]);
        $client = $this->client($http, $clock);
        $pauses = [];
        $client->onPause(static function (int $seconds, string $reason) use (&$pauses): void {
            $pauses[] = [$seconds, $reason];
        });

        $journeys = $client->journeys(self::FROM, self::TO, new \DateTimeImmutable('2026-10-13'));

        self::assertCount(1, $journeys);
        self::assertSame([[120, 'quota reached']], $pauses);
        self::assertSame('10:02:00', $clock->now()->format('H:i:s'));
        self::assertSame(2, $client->requestCount());
    }

    public function testQuotaRefusalsBackOffProgressivelyWithoutRetryAfter(): void
    {
        $clock = new MockClock('2026-10-10 10:00:00');
        $http = new MockHttpClient([
            new MockResponse('', ['http_code' => 429]),
            new MockResponse('quota exceeded', ['http_code' => 403]),
            new MockResponse('{"journeys": []}'),
        ]);
        $client = $this->client($http, $clock);
        $pauses = [];
        $client->onPause(static function (int $seconds) use (&$pauses): void {
            $pauses[] = $seconds;
        });

        $client->journeys(self::FROM, self::TO, new \DateTimeImmutable('2026-10-13'));

        self::assertSame([60, 300], $pauses);
    }

    private function client(MockHttpClient $http, ?MockClock $clock = null, string $token = 'token'): SncfApiClient
    {
        return new SncfApiClient($http, $clock ?? new MockClock('2026-10-10 10:00:00'), $token, 0.0);
    }
}
