<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\Departement;
use App\Enum\Region;
use App\Service\Import\CsvReader;
use PHPUnit\Framework\TestCase;

final class DepartementTest extends TestCase
{
    public function testEveryDepartementHasARegionAndEveryRegionHasDepartements(): void
    {
        $regionsSeen = [];
        foreach (Departement::cases() as $departement) {
            $regionsSeen[$departement->region()->value] = true;
        }

        self::assertCount(\count(Region::cases()), $regionsSeen);

        $total = 0;
        foreach (Region::cases() as $region) {
            self::assertNotSame([], $region->departements(), $region->label());
            $total += \count($region->departements());
        }

        self::assertSame(\count(Departement::cases()), $total);
    }

    public function testRegionOfADepartement(): void
    {
        self::assertSame(Region::Bretagne, Departement::Morbihan->region());
        self::assertSame(Region::Corse, Departement::from('2A')->region());
    }

    public function testVendoredCommunesOnlyReferenceKnownDepartements(): void
    {
        $count = 0;
        foreach (new CsvReader()->read(\dirname(__DIR__, 2).'/resources/geo/communes-unites-urbaines.csv') as $row) {
            self::assertNotNull(Departement::tryFrom($row['dep_code']), \sprintf('Unknown département "%s".', $row['dep_code']));
            ++$count;
        }

        self::assertGreaterThan(0, $count);
    }
}
