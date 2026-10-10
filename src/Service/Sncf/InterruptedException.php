<?php

declare(strict_types=1);

namespace App\Service\Sncf;

/** The caller asked to stop while the client was waiting (see SncfApiClient::interruptWhen()). */
final class InterruptedException extends SncfApiException
{
}
