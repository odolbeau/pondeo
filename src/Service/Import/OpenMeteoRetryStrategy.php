<?php

declare(strict_types=1);

namespace App\Service\Import;

use Symfony\Component\HttpClient\Response\AsyncContext;
use Symfony\Component\HttpClient\Retry\GenericRetryStrategy;
use Symfony\Component\HttpClient\Retry\RetryStrategyInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Open-Meteo answers 429 for its minutely, hourly and daily quotas alike. Only
 * the minutely one is worth waiting for within a run: the others last too long,
 * so the download stops there and is resumed later.
 */
final readonly class OpenMeteoRetryStrategy implements RetryStrategyInterface
{
    private GenericRetryStrategy $inner;

    public function __construct()
    {
        $this->inner = new GenericRetryStrategy([429, 502, 503, 504], delayMs: 61_000, multiplier: 1.0);
    }

    public function shouldRetry(AsyncContext $context, ?string $responseContent, ?TransportExceptionInterface $exception): ?bool
    {
        if (429 === $context->getStatusCode()) {
            if (null === $responseContent) {
                return null; // Asks for the body, to tell the quotas apart.
            }

            if (!str_contains($responseContent, 'Minutely')) {
                return false;
            }
        }

        return $this->inner->shouldRetry($context, $responseContent, $exception);
    }

    public function getDelay(AsyncContext $context, ?string $responseContent, ?TransportExceptionInterface $exception): int
    {
        return $this->inner->getDelay($context, $responseContent, $exception);
    }
}
