<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Service\Import\CsvReader;
use App\Service\Import\StationJourneySourceDownloader;
use App\Service\Sncf\SncfApiClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class StationJourneySourceDownloaderTest extends TestCase
{
    private const string HEADER = "id_gare,name,lat,lon,code_commune,code_uic\n";

    private const string DIRECT_ONLY = '{"journeys": [{"departure_date_time": "20261013T053600", "duration": 10500, "nb_transfers": 0}, {"departure_date_time": "20261013T063300", "duration": 10680, "nb_transfers": 0}]}';

    private const string NO_SOLUTION = '{"error": {"id": "no_solution", "message": "no solution found for this journey"}}';

    private const string UNKNOWN = '{"error": {"id": "unknown_object", "message": "not valid"}}';

    private string $dir;

    /** @var list<string> requests received, as "uic|destination|max-transfers" */
    private array $requests = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/station-journey-downloader-test-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->dir.'/*') ?: []);
        rmdir($this->dir);
    }

    public function testDirectTrainsSettleItInOneRequest(): void
    {
        $this->writeStations(['s1,La Souterraine,46.2,1.4,23176,87592378']);

        $result = $this->download(['87592378|paris|0' => self::DIRECT_ONLY, '87592378|nantes|0' => self::DIRECT_ONLY]);

        self::assertSame(2, $result['computed']);
        self::assertSame(2, $result['requests']);
        self::assertSame(['87592378|nantes|0', '87592378|paris|0'], $this->sortedRequests());
        self::assertSame([
            's1,nantes,2026-10-13,0,0536:175;0633:178',
            's1,paris,2026-10-13,0,0536:175;0633:178',
        ], $this->resultLines());
    }

    public function testWithoutDirectTrainItFindsTheFewestTransfersThenAllTheJourneysAtThatLevel(): void
    {
        $this->writeStations(['s1,Rural,46.2,1.4,23176,87000001']);
        $oneChangeOnly = '{"journeys": [{"departure_date_time": "20261013T075800", "duration": 14100, "nb_transfers": 1}, {"departure_date_time": "20261013T092300", "duration": 19920, "nb_transfers": 2}]}';
        $allOneChange = '{"journeys": [{"departure_date_time": "20261013T075800", "duration": 14100, "nb_transfers": 1}, {"departure_date_time": "20261013T091100", "duration": 12120, "nb_transfers": 1}]}';

        $result = $this->download([
            '87000001|paris|0' => self::NO_SOLUTION,
            '87000001|paris|3' => $oneChangeOnly,
            '87000001|paris|1' => $allOneChange,
            '87000001|nantes|0' => self::DIRECT_ONLY,
        ]);

        self::assertSame(4, $result['requests']);
        self::assertContains('s1,paris,2026-10-13,1,0758:235;0911:202', $this->resultLines());
    }

    public function testOutOfReachDestinationIsAnEmptyRow(): void
    {
        $this->writeStations(['s1,Isolated,46.2,1.4,23176,87000001']);

        $this->download(['87000001|nantes|0' => self::DIRECT_ONLY]);

        self::assertContains('s1,paris,2026-10-13,,', $this->resultLines());
    }

    public function testTriesTheNextUicCodeWhenOneIsUnknownToTheApi(): void
    {
        $this->writeStations(['s1,Complex,46.2,1.4,23176,87000001;87000002']);

        $this->download([
            '87000001|paris|0' => self::UNKNOWN,
            '87000001|nantes|0' => self::UNKNOWN,
            '87000002|paris|0' => self::DIRECT_ONLY,
            '87000002|nantes|0' => self::UNKNOWN,
        ]);

        $lines = $this->resultLines();
        self::assertContains('s1,paris,2026-10-13,0,0536:175;0633:178', $lines);
        self::assertContains('s1,nantes,2026-10-13,,', $lines);
    }

    public function testStationsInsideTheDestinationAreNotComparedWithIt(): void
    {
        $this->writeStations([
            's-paris,Paris Gare de Lyon,48.8,2.3,75112,87686030;87686006',
            's-nantes,Nantes,47.2,-1.5,44109,87481002',
        ]);

        $this->download(['87686030|nantes|0' => self::DIRECT_ONLY, '87481002|paris|0' => self::DIRECT_ONLY]);

        self::assertSame(['87481002|paris|0', '87686030|nantes|0'], $this->sortedRequests());
        self::assertCount(2, $this->resultLines());
    }

    public function testPairsAlreadyDoneForTheDayAreSkippedAndAnotherDayIsRecomputed(): void
    {
        $this->writeStations(['s1,A,46.2,1.4,23176,87000001']);
        $this->download(['87000001|paris|0' => self::DIRECT_ONLY, '87000001|nantes|0' => self::DIRECT_ONLY]);

        $sameDay = $this->download([]);
        self::assertSame(['computed' => 0, 'skipped' => 0, 'failed' => 0, 'requests' => 0, 'completed' => true], $sameDay);

        $otherDay = $this->download(['87000001|paris|0' => self::DIRECT_ONLY, '87000001|nantes|0' => self::DIRECT_ONLY], day: '2026-10-20');
        self::assertSame(2, $otherDay['computed']);
        self::assertContains('s1,paris,2026-10-20,0,0536:175;0633:178', $this->resultLines());
    }

    public function testProcessingOneStationKeepsTheRowsOfTheOthers(): void
    {
        $this->writeStations(['s1,A,46.2,1.4,23176,87000001', 's2,B,46.3,1.5,23177,87000002']);
        $this->download(['87000001|paris|0' => self::DIRECT_ONLY, '87000001|nantes|0' => self::DIRECT_ONLY, '87000002|paris|0' => self::DIRECT_ONLY, '87000002|nantes|0' => self::DIRECT_ONLY]);

        $this->download(['87000002|paris|0' => self::NO_SOLUTION, '87000002|paris|3' => self::NO_SOLUTION, '87000002|nantes|0' => self::NO_SOLUTION, '87000002|nantes|3' => self::NO_SOLUTION], force: true, station: '87000002');

        $lines = $this->resultLines();
        self::assertCount(4, $lines);
        self::assertContains('s1,paris,2026-10-13,0,0536:175;0633:178', $lines);
        self::assertContains('s2,paris,2026-10-13,,', $lines);
    }

    public function testLimitStopsAfterThatManyStations(): void
    {
        $this->writeStations(['s1,A,46.2,1.4,23176,87000001', 's2,B,46.3,1.5,23177,87000002']);

        $result = $this->download(['87000001|paris|0' => self::DIRECT_ONLY, '87000001|nantes|0' => self::DIRECT_ONLY], limit: 1);

        self::assertSame(2, $result['computed']);
        self::assertCount(2, $this->resultLines());
    }

    public function testTheFileIsSavedBeforeAQuotaPauseAndTheRunResumes(): void
    {
        $this->writeStations(['s1,A,46.2,1.4,23176,87000001', 's2,B,46.3,1.5,23177,87000002']);
        $linesSeenAtPause = null;
        $calls = 0;
        $http = new MockHttpClient(static function (string $method, string $url) use (&$calls): MockResponse {
            ++$calls;
            // The 3rd request (first of station 2) is refused once.
            if (3 === $calls) {
                return new MockResponse('', ['http_code' => 429, 'response_headers' => ['Retry-After: 30']]);
            }

            return new MockResponse(self::DIRECT_ONLY);
        });
        $client = new SncfApiClient($http, new MockClock('2026-10-10 10:00:00'), 'token', 0.0);
        $downloader = new StationJourneySourceDownloader($client, new CsvReader());
        $messages = [];

        $result = $downloader->download($this->dir, new \DateTimeImmutable('2026-10-13'), static function (string $line) use (&$messages): void {
            $messages[] = $line;
        }, static function (): void {
            // The progress bar isn't under test.
        });
        $linesSeenAtPause = $this->resultLines();

        self::assertSame(4, $result['computed']);
        self::assertTrue($result['completed']);
        self::assertCount(4, $linesSeenAtPause);
        self::assertNotEmpty(array_filter($messages, static fn (string $message): bool => str_contains($message, 'quota reached: pausing')));
    }

    public function testAStopRequestSavesAndReportsAnIncompleteRun(): void
    {
        $this->writeStations(['s1,A,46.2,1.4,23176,87000001', 's2,B,46.3,1.5,23177,87000002']);
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse(self::DIRECT_ONLY));
        $downloader = new StationJourneySourceDownloader(new SncfApiClient($http, new MockClock(), 'token', 0.0), new CsvReader());

        $result = $downloader->download(
            $this->dir,
            new \DateTimeImmutable('2026-10-13'),
            static function (): void {
            },
            // Asked to stop while the first station is being processed: it is finished and saved, the second is not started.
            static function (int $done) use (&$downloader): void {
                if (0 === $done) {
                    $downloader->requestStop();
                }
            },
        );

        self::assertFalse($result['completed']);
        self::assertCount(2, $this->resultLines());
    }

    /**
     * @param array<string, string> $responses response bodies by "uic|destination|max-transfers" (no_solution when absent)
     *
     * @return array{computed: int, skipped: int, failed: int, requests: int, completed: bool}
     */
    private function download(array $responses, string $day = '2026-10-13', bool $force = false, ?int $limit = null, ?string $station = null): array
    {
        $this->requests = [];
        $http = new MockHttpClient(function (string $method, string $url) use ($responses): MockResponse {
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            \assert(\is_string($query['from']) && \is_string($query['to']));
            $maxTransfers = \is_string($query['max_nb_transfers'] ?? null) ? $query['max_nb_transfers'] : 'any';
            $key = \sprintf('%s|%s|%s', substr($query['from'], \strlen('stop_area:SNCF:')), str_contains($query['to'], '75056') ? 'paris' : 'nantes', $maxTransfers);
            $this->requests[] = $key;

            return new MockResponse($responses[$key] ?? self::NO_SOLUTION);
        });
        $downloader = new StationJourneySourceDownloader(new SncfApiClient($http, new MockClock(), 'token', 0.0), new CsvReader());

        return $downloader->download($this->dir, new \DateTimeImmutable($day), static function (): void {
        }, static function (): void {
        }, $force, $limit, $station);
    }

    /**
     * @param list<string> $lines
     */
    private function writeStations(array $lines): void
    {
        file_put_contents($this->dir.'/gares-voyageurs.csv', self::HEADER.implode("\n", $lines)."\n");
    }

    /**
     * @return list<string> the result file's data lines
     */
    private function resultLines(): array
    {
        $lines = file($this->dir.'/trajets-gares.csv', \FILE_IGNORE_NEW_LINES) ?: [];
        array_shift($lines);

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function sortedRequests(): array
    {
        $requests = $this->requests;
        sort($requests);

        return $requests;
    }
}
