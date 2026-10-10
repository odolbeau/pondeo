<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Enum\JourneyDestination;
use App\Service\Sncf\DateOutOfBoundsException;
use App\Service\Sncf\DayJourneys;
use App\Service\Sncf\InterruptedException;
use App\Service\Sncf\SncfApiClient;
use App\Service\Sncf\SncfApiException;
use App\Service\Sncf\UnknownStopAreaException;

/**
 * Refreshes the vendored `resources/geo/trajets-gares.csv` from the SNCF API:
 * for each station of `gares-voyageurs.csv` and each destination, the day's
 * journeys with the fewest transfers. It is a long job under an API quota, so
 * the file is its own checkpoint: pairs already computed for the same day are
 * skipped, and the file is saved regularly, before every quota pause and on
 * the way out, however the run ends.
 */
final class StationJourneySourceDownloader
{
    /** Beyond this many transfers a destination counts as out of reach. */
    public const int MAX_TRANSFERS = 3;

    private const int SAVE_EVERY_STATIONS = 20;

    /** A run hitting this many failed pairs in a row stops: the API is likely down. */
    private const int MAX_CONSECUTIVE_FAILURES = 20;

    private const array CSV_COLUMNS = ['id_gare', 'destination', 'reference_date', 'transfers', 'departures'];

    private bool $stopRequested = false;

    /** Recompute pairs even when already done for the day. */
    private bool $force = false;

    public function __construct(
        private readonly SncfApiClient $apiClient,
        private readonly CsvReader $csvReader,
    ) {
    }

    /** Asks a running download to finish the current station, save and return. */
    public function requestStop(): void
    {
        $this->stopRequested = true;
    }

    /**
     * @param \Closure(string): void                                     $progress  one line per notable event (quota pause, failed pair...)
     * @param \Closure(int $done, int $total, string $stationName): void $onStation called before each station
     * @param ?string                                                    $station   only this station (UIC code or station id)
     *
     * @return array{computed: int, skipped: int, failed: int, requests: int, completed: bool} completed = false when stopped early
     *
     * @throws DateOutOfBoundsException
     * @throws SncfApiException
     */
    public function download(
        string $targetDir,
        \DateTimeImmutable $day,
        \Closure $progress,
        \Closure $onStation,
        bool $force = false,
        ?int $limit = null,
        ?string $station = null,
    ): array {
        $this->stopRequested = false;
        $this->force = $force;
        $this->apiClient->interruptWhen(fn (): bool => $this->stopRequested);

        $targetPath = $targetDir.'/trajets-gares.csv';
        // All the stations, to keep the whole file whatever subset is processed.
        $stations = $this->loadStations($targetDir.'/gares-voyageurs.csv', null);
        $targets = null === $station ? $stations : $this->loadStations($targetDir.'/gares-voyageurs.csv', $station);

        /** @var array<string, array<string, string>> $rows result rows by "id_gare|destination" */
        $rows = $this->loadRows($targetPath);

        $this->apiClient->onPause(function (int $seconds, string $reason) use ($progress, $stations, &$rows, $targetPath): void {
            // Saved before waiting: a pause can last hours, and the run may be killed meanwhile.
            $this->save($targetPath, $this->orderedRows($rows, $stations));
            $progress(\sprintf('%s: pausing %d min, resuming at %s', $reason, (int) ceil($seconds / 60), new \DateTimeImmutable("+{$seconds} seconds")->format('H:i')));
        });

        $pending = array_values(array_filter($targets, fn (array $s): bool => $this->hasPendingPair($s, $rows, $day)));
        if (null !== $limit) {
            $pending = \array_slice($pending, 0, $limit);
        }
        $computed = 0;
        $skipped = 0;
        $failed = 0;
        $consecutiveFailures = 0;
        $startRequests = $this->apiClient->requestCount();
        $completed = true;

        try {
            foreach ($pending as $index => $current) {
                if ($this->stopRequested) {
                    $completed = false;

                    break;
                }

                $onStation($index, \count($pending), $current['name']);

                foreach (JourneyDestination::cases() as $destination) {
                    $key = $current['id_gare'].'|'.$destination->value;
                    if ($destination->isInside($current['code_uic'], $current['code_commune']) || $this->isDone($rows[$key] ?? null, $day)) {
                        ++$skipped;

                        continue;
                    }

                    try {
                        $journeys = $this->findJourneys($current['code_uic'], $destination, $day);
                    } catch (DateOutOfBoundsException|InterruptedException $e) {
                        throw $e;
                    } catch (SncfApiException $e) {
                        // Not written: the pair stays pending and is retried on the next run.
                        $progress(\sprintf('%s → %s failed: %s', $current['name'], $destination->label(), $e->getMessage()));
                        ++$failed;
                        if (++$consecutiveFailures >= self::MAX_CONSECUTIVE_FAILURES) {
                            throw new SncfApiException('Too many consecutive failures, stopping.', $e->getCode(), previous: $e);
                        }

                        continue;
                    }

                    $consecutiveFailures = 0;
                    $rows[$key] = [
                        'id_gare' => $current['id_gare'],
                        'destination' => $destination->value,
                        'reference_date' => $day->format('Y-m-d'),
                        'transfers' => null === $journeys ? '' : (string) $journeys->transfers,
                        'departures' => $journeys?->departuresToCsv() ?? '',
                    ];
                    ++$computed;
                }

                if (0 === ($index + 1) % self::SAVE_EVERY_STATIONS) {
                    $this->save($targetPath, $this->orderedRows($rows, $stations));
                }
            }
        } catch (InterruptedException) {
            $completed = false;
        } finally {
            $this->save($targetPath, $this->orderedRows($rows, $stations));
        }

        return ['computed' => $computed, 'skipped' => $skipped, 'failed' => $failed, 'requests' => $this->apiClient->requestCount() - $startRequests, 'completed' => $completed && !$this->stopRequested];
    }

    /**
     * The journeys with the fewest transfers over the day, or null when the
     * destination is out of reach. A direct train settles it in one request;
     * otherwise the fewest transfers are found first, then all the journeys
     * at that level are fetched, since the first answer mixes levels.
     */
    private function findJourneys(string $uicCodes, JourneyDestination $destination, \DateTimeImmutable $day): ?DayJourneys
    {
        foreach (explode(';', $uicCodes) as $uic) {
            $from = 'stop_area:SNCF:'.$uic;

            try {
                $direct = $this->apiClient->journeys($from, $destination->navitiaId(), $day, 0);
                if ([] !== $direct) {
                    return DayJourneys::fromJourneys($direct);
                }

                $any = $this->apiClient->journeys($from, $destination->navitiaId(), $day, self::MAX_TRANSFERS);
                $fewest = DayJourneys::fromJourneys($any);
                if (null === $fewest) {
                    return null;
                }

                $atThatLevel = $this->apiClient->journeys($from, $destination->navitiaId(), $day, $fewest->transfers);

                return DayJourneys::fromJourneys([] === $atThatLevel ? $any : $atThatLevel);
            } catch (UnknownStopAreaException) {
                // This UIC code isn't in the API: try the station's next one.
                continue;
            }
        }

        return null;
    }

    /**
     * @param array<string, array<string, string>>                           $rows
     * @param array{id_gare: string, code_uic: string, code_commune: string} $station
     */
    private function hasPendingPair(array $station, array $rows, \DateTimeImmutable $day): bool
    {
        return array_any(JourneyDestination::cases(), fn (JourneyDestination $destination): bool => !$destination->isInside($station['code_uic'], $station['code_commune'])
            && !$this->isDone($rows[$station['id_gare'].'|'.$destination->value] ?? null, $day));
    }

    /**
     * @param ?array<string, string> $row
     */
    private function isDone(?array $row, \DateTimeImmutable $day): bool
    {
        return !$this->force && null !== $row && $row['reference_date'] === $day->format('Y-m-d');
    }

    /**
     * @return list<array{id_gare: string, name: string, code_uic: string, code_commune: string}>
     */
    private function loadStations(string $path, ?string $filter): array
    {
        $stations = [];
        foreach ($this->csvReader->read($path) as $row) {
            if (null !== $filter && $row['id_gare'] !== $filter && !\in_array($filter, explode(';', $row['code_uic']), true)) {
                continue;
            }

            $stations[] = ['id_gare' => $row['id_gare'], 'name' => $row['name'], 'code_uic' => $row['code_uic'], 'code_commune' => $row['code_commune']];
        }

        return $stations;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function loadRows(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $rows = [];
        foreach ($this->csvReader->read($path) as $row) {
            $rows[$row['id_gare'].'|'.$row['destination']] = $row;
        }

        return $rows;
    }

    /**
     * The file keeps the order of the stations file, so a refresh produces a
     * readable diff; rows of stations no longer listed are dropped.
     *
     * @param array<string, array<string, string>>                                               $rows
     * @param list<array{id_gare: string, name: string, code_uic: string, code_commune: string}> $stations
     *
     * @return list<array<string, string>>
     */
    private function orderedRows(array $rows, array $stations): array
    {
        $ordered = [];
        foreach ($stations as $station) {
            foreach (JourneyDestination::cases() as $destination) {
                $row = $rows[$station['id_gare'].'|'.$destination->value] ?? null;
                if (null !== $row) {
                    $ordered[] = $row;
                }
            }
        }

        return $ordered;
    }

    /**
     * @param list<array<string, string>> $rows
     */
    private function save(string $path, array $rows): void
    {
        // Written aside then renamed: a kill mid-write must not truncate the checkpoint.
        $temporary = $path.'.tmp';
        $handle = fopen($temporary, 'w') ?: throw new \RuntimeException("Cannot write {$temporary}");
        fputcsv($handle, self::CSV_COLUMNS, escape: '\\');
        foreach ($rows as $row) {
            fputcsv($handle, array_map(static fn (string $column): string => $row[$column], self::CSV_COLUMNS), escape: '\\');
        }

        fclose($handle);
        rename($temporary, $path);
    }
}
