<?php

declare(strict_types=1);

namespace App\Service\Filter;

use App\Enum\JourneyDestination;
use App\Enum\RealEstatePriceType;
use App\Service\Map\CriterionStatsProvider;

/**
 * What the filters panel needs to be drawn, the same on every page that has it:
 * the bounds of the population slider, the reference points under the
 * thresholds and the cities a train time can be set to.
 */
final readonly class FilterPanelDataProvider
{
    public function __construct(
        private PopulationRangeProvider $populationRangeProvider,
        private CriterionStatsProvider $criterionStatsProvider,
    ) {
    }

    /**
     * @return array{populationMin: int, populationMax: int, stats: array<string, array{min: float, p10: float, p25: float, median: float, p75: float, p90: float, max: float}>, journeyDestinations: list<JourneyDestination>}
     */
    public function provide(): array
    {
        try {
            $bounds = $this->populationRangeProvider->getBounds($this->populationRangeProvider->getLatestPeriod());
        } catch (\LogicException) {
            // No population yet: nothing to bound, and the panel must not take the page down.
            $bounds = ['min' => 0, 'max' => 0];
        }

        return [
            'populationMin' => $bounds['min'],
            'populationMax' => $bounds['max'],
            'stats' => $this->criterionStatsProvider->findStats([
                'commerces_total_pour_1000_hab',
                'restaurants_pour_1000_hab',
                ...array_map(static fn (RealEstatePriceType $type): string => $type->criterionCode(), RealEstatePriceType::cases()),
            ]),
            'journeyDestinations' => JourneyDestination::cases(),
        ];
    }
}
