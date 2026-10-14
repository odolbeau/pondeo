<?php

declare(strict_types=1);

namespace App\Tests\Service\Detail;

use App\Service\Detail\CriterionTrend;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CriterionTrendTest extends TestCase
{
    public function testNeedsTwoPeriods(): void
    {
        self::assertNull(CriterionTrend::fromSeries([2023 => 10], 'hab.', false));
    }

    public function testRelativeChangeSinceTheFirstPeriod(): void
    {
        $trend = CriterionTrend::fromSeries([2021 => 2000, 2023 => 2500, 2025 => 3000], '€/m²', false);

        self::assertNotNull($trend);
        self::assertSame(2021, $trend->since);
        self::assertEqualsWithDelta(50.0, $trend->change, 0.001);
        self::assertFalse($trend->inPoints);
    }

    public function testShareIsComparedInPoints(): void
    {
        $trend = CriterionTrend::fromSeries([2012 => 8.0, 2022 => 10.5], '%', true);

        self::assertNotNull($trend);
        self::assertEqualsWithDelta(2.5, $trend->change, 0.001);
        self::assertTrue($trend->inPoints);
    }

    public function testNoRelativeChangeFromZero(): void
    {
        self::assertNull(CriterionTrend::fromSeries([2012 => 0, 2022 => 5], 'j/an', false));
    }

    #[DataProvider('provideFavorability')]
    public function testFavorabilityFollowsTheRankOrder(float $last, bool $lowerIsBetter, ?bool $expected): void
    {
        $trend = CriterionTrend::fromSeries([2012 => 100.0, 2022 => $last], 'x', $lowerIsBetter);

        self::assertNotNull($trend);
        self::assertSame($expected, $trend->favorable);
    }

    /**
     * @return iterable<string, array{float, bool, ?bool}>
     */
    public static function provideFavorability(): iterable
    {
        yield 'rise, higher is better' => [110.0, false, true];
        yield 'fall, higher is better' => [90.0, false, false];
        yield 'rise, lower is better' => [110.0, true, false];
        yield 'fall, lower is better' => [90.0, true, true];
        yield 'tiny change is stable' => [100.2, true, null];
    }
}
