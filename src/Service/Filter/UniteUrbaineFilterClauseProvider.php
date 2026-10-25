<?php

declare(strict_types=1);

namespace App\Service\Filter;

use App\Enum\AttractionCategory;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;

/**
 * Turns a {@see UniteUrbaineFilter} into SQL, once for every page that filters
 * unités urbaine: the map, its export, the rankings and the personal ranking
 * keep the same ones.
 *
 * A unité urbaine with no attraction category is never excluded: unknown is not
 * the same as satellite. Likewise, with a density minimum (shops or
 * restaurants), a unité urbaine without a density value is left out: it cannot
 * be said to reach it. With a nuclear exclusion radius, a unité urbaine whose
 * centroid is within it of a plant is left out. A price per m² maximum leaves
 * out a unité urbaine without a price (Alsace-Moselle is not in the source) for
 * the same reason as the density minimum. With `withStation`, only the unités
 * urbaine containing a railway station are kept; likewise with
 * `withBoardGameAssociation` and a board game association. A train limit keeps a
 * unité urbaine if, from one of its closest stations, the car ride plus the
 * train to the destination fits in the minutes. A station without a journey row
 * is inside the destination (train: 0); a station that cannot reach it does
 * not count, nor does a unité urbaine without access stations. A transfers
 * maximum also discards the stations whose fewest transfers to the destination
 * exceed it.
 */
final readonly class UniteUrbaineFilterClauseProvider
{
    public function __construct(private PopulationRangeProvider $populationRangeProvider)
    {
    }

    public function provide(UniteUrbaineFilter $filter): UniteUrbaineFilterClause
    {
        $excludedCategoryValues = array_map(static fn (AttractionCategory $category): string => $category->value, $filter->excludedAttractionCategories);

        $trainClauses = '';
        $trainParams = [];
        $i = 0;
        foreach ($filter->trainLimits as $destination => $minutes) {
            $trainClauses .= <<<SQL

                  AND EXISTS (
                      SELECT 1
                      FROM station_access sa
                      LEFT JOIN station_journey sj ON sj.station_id = sa.station_id AND sj.destination = :fTrainDestination{$i}
                      WHERE sa.unite_urbaine_id = uu.id
                        AND (sj.id IS NULL OR (sj.median_duration_minutes IS NOT NULL AND (CAST(:fTrainTransfers AS integer) IS NULL OR sj.transfers <= CAST(:fTrainTransfers AS integer))))
                        AND sa.duration_seconds / 60.0 + COALESCE(sj.median_duration_minutes, 0) <= :fTrainMinutes{$i}
                  )
                SQL;
            $trainParams["fTrainDestination{$i}"] = $destination;
            $trainParams["fTrainMinutes{$i}"] = $minutes;
            ++$i;
        }

        $sql = <<<'SQL'
                FROM unite_urbaine uu
                INNER JOIN criterion_value cv ON cv.unite_urbaine_id = uu.id
                WHERE cv.criterion_id = :fPopulationCriterion
                  AND cv.period = :fPeriod
                  AND (CAST(:fPopulationMin AS integer) IS NULL OR cv.value_integer >= CAST(:fPopulationMin AS integer))
                  AND (CAST(:fPopulationMax AS integer) IS NULL OR cv.value_integer <= CAST(:fPopulationMax AS integer))
                  AND (uu.attraction_category IS NULL OR uu.attraction_category NOT IN (:fExcludedCategories))
                  AND (CAST(:fCommerceDensityMin AS numeric) IS NULL OR EXISTS (
                      SELECT 1
                      FROM criterion_value density
                      INNER JOIN criterion dc ON dc.id = density.criterion_id
                      WHERE dc.code = 'commerces_total_pour_1000_hab'
                        AND density.unite_urbaine_id = uu.id
                        AND density.period = (
                            SELECT MAX(period) FROM criterion_value WHERE criterion_id = dc.id
                        )
                        AND density.value_decimal >= CAST(:fCommerceDensityMin AS numeric)
                  ))
                  AND (CAST(:fRestaurantDensityMin AS numeric) IS NULL OR EXISTS (
                      SELECT 1
                      FROM criterion_value restaurant_density
                      INNER JOIN criterion rc ON rc.id = restaurant_density.criterion_id
                      WHERE rc.code = 'restaurants_pour_1000_hab'
                        AND restaurant_density.unite_urbaine_id = uu.id
                        AND restaurant_density.period = (
                            SELECT MAX(period) FROM criterion_value WHERE criterion_id = rc.id
                        )
                        AND restaurant_density.value_decimal >= CAST(:fRestaurantDensityMin AS numeric)
                  ))
                  AND (CAST(:fNuclearKm AS numeric) IS NULL OR NOT EXISTS (
                      SELECT 1
                      FROM poi plant
                      INNER JOIN poi_category plant_category ON plant_category.id = plant.category_id
                      WHERE plant_category.code = 'nuclear_power_plant'
                        AND ST_DWithin(uu.centroid, plant.location, CAST(:fNuclearKm AS numeric) * 1000)
                  ))
                  AND (CAST(:fPriceMax AS numeric) IS NULL OR EXISTS (
                      SELECT 1
                      FROM criterion_value price
                      INNER JOIN criterion pc ON pc.id = price.criterion_id
                      WHERE pc.code = :fPriceCriterion
                        AND price.unite_urbaine_id = uu.id
                        AND price.period = (
                            SELECT MAX(period) FROM criterion_value WHERE criterion_id = pc.id
                        )
                        AND price.value_integer <= CAST(:fPriceMax AS numeric)
                    ))
                  AND (NOT :fWithStation OR EXISTS (
                      SELECT 1
                      FROM poi station
                      INNER JOIN poi_category station_category ON station_category.id = station.category_id
                      WHERE station_category.code = 'railway_station'
                        AND station.unite_urbaine_id = uu.id
                  ))
                  AND (NOT :fWithBoardGameAssociation OR EXISTS (
                      SELECT 1
                      FROM poi association
                      INNER JOIN poi_category association_category ON association_category.id = association.category_id
                      INNER JOIN poi_category association_group ON association_group.id = association_category.parent_id
                      WHERE association_group.code = 'board_game_association'
                        AND association.unite_urbaine_id = uu.id
                  ))
                SQL.$trainClauses;

        return new UniteUrbaineFilterClause(
            $sql,
            $trainParams + ['fTrainTransfers' => $filter->trainMaxTransfers] + [
                'fPopulationCriterion' => $this->populationRangeProvider->getCriterionId(),
                'fPeriod' => $this->populationRangeProvider->getLatestPeriod(),
                'fPopulationMin' => $filter->populationMin,
                'fPopulationMax' => $filter->populationMax,
                'fCommerceDensityMin' => $filter->commerceDensityMin,
                'fRestaurantDensityMin' => $filter->restaurantDensityMin,
                'fNuclearKm' => $filter->nuclearExclusionKm,
                'fPriceMax' => $filter->priceMax,
                'fPriceCriterion' => $filter->priceCriterionCode,
                'fWithStation' => $filter->withStation,
                'fWithBoardGameAssociation' => $filter->withBoardGameAssociation,
                // An empty IN () list is invalid SQL: a value no category has keeps the clause a no-op.
                'fExcludedCategories' => [] === $excludedCategoryValues ? [''] : $excludedCategoryValues,
            ],
            ['fExcludedCategories' => ArrayParameterType::STRING, 'fWithStation' => ParameterType::BOOLEAN, 'fWithBoardGameAssociation' => ParameterType::BOOLEAN],
        );
    }
}
