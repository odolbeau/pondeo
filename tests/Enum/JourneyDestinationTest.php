<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\JourneyDestination;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JourneyDestinationTest extends TestCase
{
    #[DataProvider('provideInside')]
    public function testIsInside(JourneyDestination $destination, string $uicCodes, string $inseeCommuneCode, bool $expected): void
    {
        self::assertSame($expected, $destination->isInside($uicCodes, $inseeCommuneCode));
    }

    /**
     * @return iterable<string, array{JourneyDestination, string, string, bool}>
     */
    public static function provideInside(): iterable
    {
        yield 'Paris station' => [JourneyDestination::Paris, '87686030;87686006', '75112', true];
        yield 'Nantes main station' => [JourneyDestination::Nantes, '87481002', '44109', true];
        yield 'Nantes Pirmil' => [JourneyDestination::Nantes, '87481234', '44109', false];
        yield 'Marseille Saint-Charles' => [JourneyDestination::Marseille, '87751008', '13201', true];
        yield 'Marseille Blancarde' => [JourneyDestination::Marseille, '87751081', '13204', false];
        yield 'Lyon Part-Dieu' => [JourneyDestination::Lyon, '87723197', '69383', true];
        yield 'Lyon Vaise' => [JourneyDestination::Lyon, '87721001', '69389', true];
        yield 'Lyon Saint-Exupéry TGV' => [JourneyDestination::Lyon, '87762906', '69299', false];
        yield 'Vienne' => [JourneyDestination::Lyon, '87722587', '38544', false];
    }
}
