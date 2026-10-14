<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\AssociationFamily;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AssociationFamilyTest extends TestCase
{
    #[DataProvider('provideObjectCodes')]
    public function testFamilyOfAnObjectCode(string $objectCode, ?AssociationFamily $expected): void
    {
        self::assertSame($expected, AssociationFamily::fromObjectCode($objectCode));
    }

    /**
     * @return iterable<string, array{string, ?AssociationFamily}>
     */
    public static function provideObjectCodes(): iterable
    {
        yield 'sport' => ['011035', AssociationFamily::Sport];
        yield 'hunting is outdoor sport' => ['013005', AssociationFamily::Sport];
        yield 'culture' => ['006030', AssociationFamily::Culture];
        yield 'politics has no family' => ['001005', null];
        yield 'empty' => ['', null];
    }

    public function testNoThemeBelongsToTwoFamilies(): void
    {
        $themes = array_merge(...array_map(static fn (AssociationFamily $family): array => $family->themes(), AssociationFamily::cases()));

        self::assertSame($themes, array_unique($themes));
    }
}
