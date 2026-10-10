<?php

declare(strict_types=1);

namespace App\Service\Sncf;

/** The requested day is outside the timetable period the API currently serves. */
final class DateOutOfBoundsException extends SncfApiException
{
}
