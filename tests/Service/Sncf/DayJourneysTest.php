<?php

declare(strict_types=1);

namespace App\Tests\Service\Sncf;

use App\Service\Sncf\DayJourneys;
use App\Service\Sncf\Journey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DayJourneysTest extends TestCase
{
    public function testKeepsOnlyTheJourneysWithTheFewestTransfers(): void
    {
        $day = DayJourneys::fromJourneys([
            $this->journey('0536', 175, 0),
            $this->journey('0758', 235, 1),
            $this->journey('0633', 178, 0),
            $this->journey('0923', 332, 2),
        ]);

        self::assertNotNull($day);
        self::assertSame(0, $day->transfers);
        self::assertSame(2, $day->trainsPerDay());
        self::assertSame('0536:175;0633:178', $day->departuresToCsv());
    }

    public function testWithoutDirectTrainTheOneTransferJourneysCount(): void
    {
        $day = DayJourneys::fromJourneys([$this->journey('0758', 235, 1), $this->journey('0911', 202, 1), $this->journey('0923', 332, 2)]);

        self::assertNotNull($day);
        self::assertSame(1, $day->transfers);
        self::assertSame(2, $day->trainsPerDay());
    }

    public function testNoJourneyMeansNothing(): void
    {
        self::assertNull(DayJourneys::fromJourneys([]));
        self::assertNull(DayJourneys::fromCsv('', ''));
    }

    public function testMedianOfAnOddNumberOfDurations(): void
    {
        $day = DayJourneys::fromJourneys([$this->journey('0536', 175, 0), $this->journey('0633', 190, 0), $this->journey('0736', 176, 0)]);

        self::assertNotNull($day);
        self::assertSame(176, $day->medianDurationMinutes());
    }

    public function testMedianOfAnEvenNumberOfDurationsIsRoundedToTheMinute(): void
    {
        $day = DayJourneys::fromJourneys([$this->journey('0536', 175, 0), $this->journey('0633', 178, 0)]);

        self::assertNotNull($day);
        self::assertSame(177, $day->medianDurationMinutes());
    }

    public function testTwoJourneysLeavingTogetherCountOnceWithTheFastestDuration(): void
    {
        $day = DayJourneys::fromJourneys([$this->journey('1536', 190, 0), $this->journey('1536', 183, 0)]);

        self::assertNotNull($day);
        self::assertSame(1, $day->trainsPerDay());
        self::assertSame(183, $day->medianDurationMinutes());
    }

    public function testRoundTripsThroughTheCsvRepresentation(): void
    {
        $day = DayJourneys::fromCsv('1', '0758:235;0911:202');

        self::assertNotNull($day);
        self::assertSame(1, $day->transfers);
        self::assertSame(2, $day->trainsPerDay());
        self::assertSame(219, $day->medianDurationMinutes());
        self::assertSame('0758:235;0911:202', $day->departuresToCsv());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function malformedCsv(): iterable
    {
        yield 'wrong separator' => ['0', '0536-175'];
        yield 'extra field' => ['0', '1200:30:5'];
        yield 'time too short' => ['0', '536:175'];
        yield 'non numeric transfers' => ['abc', '0536:175'];
    }

    #[DataProvider('malformedCsv')]
    public function testRejectsMalformedCsv(string $transfers, string $departures): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DayJourneys::fromCsv($transfers, $departures);
    }

    public function testFewerTransfersThenMoreDeparturesIsBetter(): void
    {
        $direct = DayJourneys::fromCsv('0', '0536:175');
        $directMore = DayJourneys::fromCsv('0', '0536:175;0633:178');
        $change = DayJourneys::fromCsv('1', '0536:175;0633:178;0700:180');

        self::assertNotNull($direct);
        self::assertNotNull($directMore);
        self::assertNotNull($change);
        self::assertTrue($direct->isBetterThan($change));
        self::assertTrue($directMore->isBetterThan($direct));
        self::assertFalse($change->isBetterThan($direct));
    }

    private function journey(string $departure, int $minutes, int $transfers): Journey
    {
        return new Journey(new \DateTimeImmutable('2026-10-13 '.substr($departure, 0, 2).':'.substr($departure, 2)), $minutes * 60, $transfers);
    }
}
