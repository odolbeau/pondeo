<?php

declare(strict_types=1);

namespace App\Service\Sncf;

use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The only access point to the SNCF API (Navitia). Spaces its requests out
 * and, when the quota is exhausted, waits as long as needed and resumes
 * instead of failing: the daily quota of the token is unknown and the API
 * advertises no rate-limit headers, so a refusal (HTTP 429, or a 403 that
 * mentions the quota) is the only signal.
 */
final class SncfApiClient
{
    /** Pauses, in seconds, after successive refusals; the last one repeats. */
    private const array BACKOFF_SECONDS = [60, 300, 900, 1_800, 3_600];

    private const int MAX_TRANSPORT_FAILURES = 5;

    private const int MAX_PAUSE_SECONDS = 6 * 3_600;

    private ?\Closure $pauseListener = null;

    private ?\DateTimeImmutable $lastRequestAt = null;

    private int $requestCount = 0;

    public function __construct(
        #[Target('sncf.client')]
        private readonly HttpClientInterface $sncfClient,
        private readonly ClockInterface $clock,
        #[Autowire(env: 'SNCF_API_TOKEN')]
        private readonly string $token = '',
        private readonly float $minIntervalSeconds = 0.25,
    ) {
    }

    /**
     * @param \Closure(int $seconds, string $reason): void $listener called before each pause
     */
    public function onPause(\Closure $listener): void
    {
        $this->pauseListener = $listener;
    }

    /** Number of HTTP requests sent so far (retries after a pause included). */
    public function requestCount(): int
    {
        return $this->requestCount;
    }

    /**
     * Journeys leaving during the whole of $day, rail only (coaches and buses
     * excluded), on the planned timetable. Empty when the API finds none.
     *
     * @param ?int $maxTransfers null for no cap
     *
     * @return list<Journey>
     *
     * @throws UnknownStopAreaException
     * @throws DateOutOfBoundsException
     * @throws SncfApiException
     */
    public function journeys(string $from, string $to, \DateTimeImmutable $day, ?int $maxTransfers = null, int $count = 50): array
    {
        $query = http_build_query(array_filter([
            'from' => $from,
            'to' => $to,
            'datetime' => $day->format('Ymd').'T000000',
            'timeframe_duration' => 86_400,
            'data_freshness' => 'base_schedule',
            'count' => $count,
            'max_nb_transfers' => $maxTransfers,
        ], static fn (mixed $value): bool => null !== $value));

        // Repeated "forbidden_uris[]": the API wants this exact key, which a
        // query array option would index.
        $url = 'journeys?'.$query.'&forbidden_uris[]=physical_mode:Coach&forbidden_uris[]=physical_mode:Bus';

        /** @var array{error?: array{id: string, message: string}, journeys?: list<array{departure_date_time: string, duration: int, nb_transfers: int}>} $data */
        $data = $this->get($url);

        $errorId = $data['error']['id'] ?? null;
        if ('no_solution' === $errorId) {
            return [];
        }

        if (null !== $errorId) {
            throw new SncfApiException(\sprintf('%s: %s', $errorId, $data['error']['message'] ?? ''));
        }

        $timezone = new \DateTimeZone('Europe/Paris');
        $journeys = [];
        foreach ($data['journeys'] ?? [] as $journey) {
            $departure = \DateTimeImmutable::createFromFormat('Ymd\THis', $journey['departure_date_time'], $timezone);
            if (false === $departure) {
                throw new SncfApiException(\sprintf('Unexpected departure "%s".', $journey['departure_date_time']));
            }

            $journeys[] = new Journey($departure, $journey['duration'], $journey['nb_transfers']);
        }

        return $journeys;
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $url): array
    {
        if ('' === $this->token) {
            throw new SncfApiException('SNCF_API_TOKEN is empty: set it in .env.local.');
        }

        $quotaRefusals = 0;
        $transportFailures = 0;

        while (true) {
            $this->throttle();

            try {
                $response = $this->sncfClient->request('GET', $url);
                $status = $response->getStatusCode();
                $body = $response->getContent(false);
            } catch (TransportExceptionInterface $e) {
                if (++$transportFailures > self::MAX_TRANSPORT_FAILURES) {
                    throw new SncfApiException('The SNCF API is unreachable: '.$e->getMessage(), $e->getCode(), previous: $e);
                }

                $this->pause(self::BACKOFF_SECONDS[min($transportFailures, \count(self::BACKOFF_SECONDS)) - 1], 'network error');

                continue;
            }

            if ($this->isQuotaRefusal($status, $body)) {
                $this->pause($this->quotaPauseSeconds($response, $quotaRefusals++), 'quota reached');

                continue;
            }

            return $this->decode($status, $body);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(int $status, string $body): array
    {
        if (401 === $status || 403 === $status) {
            throw new SncfApiException(\sprintf('The SNCF API refused the token (HTTP %d).', $status));
        }

        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new SncfApiException(\sprintf('Unreadable response from the SNCF API (HTTP %d).', $status), $e->getCode(), previous: $e);
        }

        $errorId = \is_array($data['error'] ?? null) ? ($data['error']['id'] ?? null) : null;
        if ('unknown_object' === $errorId) {
            throw new UnknownStopAreaException(\is_string($data['error']['message'] ?? null) ? $data['error']['message'] : 'Unknown stop area.');
        }

        if ('date_out_of_bounds' === $errorId) {
            throw new DateOutOfBoundsException('The date is outside the timetable period served by the API.');
        }

        if ($status >= 500) {
            throw new SncfApiException(\sprintf('The SNCF API failed (HTTP %d).', $status));
        }

        return $data;
    }

    private function isQuotaRefusal(int $status, string $body): bool
    {
        if (429 === $status) {
            return true;
        }

        return 403 === $status && 1 === preg_match('/quota|rate.?limit|too many/i', $body);
    }

    private function quotaPauseSeconds(ResponseInterface $response, int $refusals): int
    {
        $retryAfter = $response->getHeaders(false)['retry-after'][0] ?? null;
        if (null !== $retryAfter && ctype_digit($retryAfter) && (int) $retryAfter > 0) {
            return min((int) $retryAfter, self::MAX_PAUSE_SECONDS);
        }

        return self::BACKOFF_SECONDS[min($refusals, \count(self::BACKOFF_SECONDS) - 1)];
    }

    private function pause(int $seconds, string $reason): void
    {
        if (null !== $this->pauseListener) {
            ($this->pauseListener)($seconds, $reason);
        }

        $this->clock->sleep($seconds);
        $this->lastRequestAt = null;
    }

    private function throttle(): void
    {
        if (null !== $this->lastRequestAt) {
            $elapsed = (float) $this->clock->now()->format('U.u') - (float) $this->lastRequestAt->format('U.u');
            if ($elapsed < $this->minIntervalSeconds) {
                $this->clock->sleep($this->minIntervalSeconds - $elapsed);
            }
        }

        $this->lastRequestAt = $this->clock->now();
        ++$this->requestCount;
    }
}
