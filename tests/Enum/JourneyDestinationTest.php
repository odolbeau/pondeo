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
        yield 'Bordeaux Saint-Jean' => [JourneyDestination::Bordeaux, '87581009', '33063', true];
        yield 'Bordeaux Cenon' => [JourneyDestination::Bordeaux, '87581595', '33119', false];
        yield 'Toulouse Matabiau' => [JourneyDestination::Toulouse, '87611004', '31555', true];
        yield 'Colomiers' => [JourneyDestination::Toulouse, '87611467', '31149', false];
        yield 'Lille Flandres' => [JourneyDestination::Lille, '87286005', '59350', true];
        yield 'Lille Europe' => [JourneyDestination::Lille, '87223263', '59350', true];
        yield 'Loos lez Lille' => [JourneyDestination::Lille, '87286112', '59360', false];
        yield 'Rennes' => [JourneyDestination::Rennes, '87471003', '35238', true];
        yield 'Vitré' => [JourneyDestination::Rennes, '87471508', '35360', false];
        yield 'Strasbourg' => [JourneyDestination::Strasbourg, '87212027', '67482', true];
        yield 'Strasbourg Roethig' => [JourneyDestination::Strasbourg, '87212191', '67267', false];
        yield 'Lyon Part-Dieu' => [JourneyDestination::Lyon, '87723197', '69383', true];
        yield 'Lyon Vaise' => [JourneyDestination::Lyon, '87721001', '69389', true];
        yield 'Lyon Saint-Exupéry TGV' => [JourneyDestination::Lyon, '87762906', '69299', false];
        yield 'Vienne' => [JourneyDestination::Lyon, '87722587', '38544', false];
    }
}
