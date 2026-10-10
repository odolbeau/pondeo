<?php

declare(strict_types=1);

namespace App\Service\Sncf;

/** The API doesn't know the origin or destination (e.g. a UIC code absent from its network). */
final class UnknownStopAreaException extends SncfApiException
{
}
