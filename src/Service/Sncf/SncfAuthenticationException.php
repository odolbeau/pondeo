<?php

declare(strict_types=1);

namespace App\Service\Sncf;

/** The token is missing or refused: no point in carrying on with other requests. */
final class SncfAuthenticationException extends SncfApiException
{
}
