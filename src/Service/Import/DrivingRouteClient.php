<?php

declare(strict_types=1);

namespace App\Service\Import;

use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fastest car route between two points, from the IGN Géoplateforme routing
 * service (BD TOPO road network, free, no key). It has no matrix endpoint:
 * one call per route.
 */
final readonly class DrivingRouteClient
{
    private const string URL = 'https://data.geopf.fr/navigation/itineraire';

    private const int ATTEMPTS = 3;

    public function __construct(private HttpClientInterface $httpClient)
    {
    }

    /**
     * @return array{distance: float, duration: float}|null meters and seconds; null when there is no route or the service keeps failing
     */
    public function route(float $fromLon, float $fromLat, float $toLon, float $toLat): ?array
    {
        for ($attempt = 1; $attempt <= self::ATTEMPTS; ++$attempt) {
            try {
                $data = $this->httpClient->request('GET', self::URL, ['query' => [
                    'resource' => 'bdtopo-osrm',
                    'profile' => 'car',
                    'optimization' => 'fastest',
                    'start' => \sprintf('%.6F,%.6F', $fromLon, $fromLat),
                    'end' => \sprintf('%.6F,%.6F', $toLon, $toLat),
                    'getSteps' => 'false',
                    'geometryFormat' => 'polyline',
                ]])->toArray();
            } catch (ClientExceptionInterface $e) {
                // 429 is the rate limit: back off and retry. Any other 4xx means "no route for these points".
                if (429 !== $e->getResponse()->getStatusCode()) {
                    return null;
                }
            } catch (ExceptionInterface) {
                // Transport or server error: retry.
            }

            if (isset($data)) {
                if (!isset($data['distance'], $data['duration']) || !is_numeric($data['distance']) || !is_numeric($data['duration'])) {
                    return null;
                }

                return ['distance' => (float) $data['distance'], 'duration' => (float) $data['duration']];
            }

            usleep(1_000_000 * $attempt);
        }

        return null;
    }
}
