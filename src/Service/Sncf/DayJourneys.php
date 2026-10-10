<?php

declare(strict_types=1);

namespace App\Service\Sncf;

/**
 * The journeys of one day between a station and a destination that have the
 * fewest transfers — the only ones that count. Kept as a list of departures
 * (time and duration) rather than as figures, so the figures can be derived
 * differently later without calling the API again.
 */
final readonly class DayJourneys
{
    /**
     * @param list<array{time: string, minutes: int}> $departures one per distinct departure time ("HHMM"), in time order
     */
    private function __construct(
        public int $transfers,
        private array $departures,
    ) {
    }

    /**
     * Keeps the journeys with the fewest transfers; null when there are none.
     * Two journeys leaving at the same minute count as one departure, the
     * fastest.
     *
     * @param list<Journey> $journeys
     */
    public static function fromJourneys(array $journeys): ?self
    {
        if ([] === $journeys) {
            return null;
        }

        $fewest = min(array_map(static fn (Journey $journey): int => $journey->transfers, $journeys));

        // Keyed with a prefix: a bare "1200" would become an integer key.
        $minutesByTime = [];
        foreach ($journeys as $journey) {
            if ($journey->transfers !== $fewest) {
                continue;
            }

            $key = 't'.$journey->departure->format('Hi');
            $minutesByTime[$key] = min($minutesByTime[$key] ?? \PHP_INT_MAX, $journey->durationMinutes());
        }

        ksort($minutesByTime);

        $departures = [];
        foreach ($minutesByTime as $key => $minutes) {
            $departures[] = ['time' => substr($key, 1), 'minutes' => $minutes];
        }

        return new self($fewest, $departures);
    }

    /**
     * @param string $departures "HHMM:minutes" entries separated by ";"
     */
    public static function fromCsv(string $transfers, string $departures): ?self
    {
        if ('' === $transfers || '' === $departures) {
            return null;
        }

        if (!ctype_digit($transfers)) {
            throw new \InvalidArgumentException(\sprintf('Invalid number of transfers "%s".', $transfers));
        }

        $parsed = [];
        foreach (explode(';', $departures) as $entry) {
            if (1 !== preg_match('/^(\d{4}):(\d+)$/', $entry, $matches)) {
                throw new \InvalidArgumentException(\sprintf('Invalid departure "%s".', $entry));
            }

            $parsed[] = ['time' => $matches[1], 'minutes' => (int) $matches[2]];
        }

        return new self((int) $transfers, $parsed);
    }

    public function departuresToCsv(): string
    {
        return implode(';', array_map(
            static fn (array $departure): string => \sprintf('%s:%d', $departure['time'], $departure['minutes']),
            $this->departures,
        ));
    }

    /** Fewer transfers first, then more departures. */
    public function isBetterThan(self $other): bool
    {
        if ($this->transfers !== $other->transfers) {
            return $this->transfers < $other->transfers;
        }

        return $this->trainsPerDay() > $other->trainsPerDay();
    }

    public function trainsPerDay(): int
    {
        return \count($this->departures);
    }

    /** Median duration, rounded to the minute: robust to the odd slow train. */
    public function medianDurationMinutes(): int
    {
        $durations = array_map(static fn (array $departure): int => $departure['minutes'], $this->departures);
        sort($durations);

        $middle = intdiv(\count($durations), 2);

        return 1 === \count($durations) % 2
            ? $durations[$middle]
            : (int) round(($durations[$middle - 1] + $durations[$middle]) / 2);
    }
}
