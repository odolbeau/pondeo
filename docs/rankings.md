# Rankings

Public pages that order the unités urbaines by one criterion: an index at
`/classements` (a top 3 per ranking, by theme) and one page per ranking at
`/classements/{criterion code}`. The home page features a few of them.

## Which criteria

Only criteria where "first" or "last" means something get a ranking: population
and its growth, density, unemployment and activity, living standard and
poverty, tax income, real-estate prices, rents (apartments and houses), vacant
housing, commerce density, association density, higher-education share, and,
from the climate and risk data, sunshine, cool
nights, summer dryness, muggy heat, the 2050 projections (hot days, tropical
nights, heat waves, warming) and the exposure to natural disasters, floods and
forest fires. Age brackets, socio-professional categories, tax brackets and
housing tenure are left out, and so are the non-comparable raw counts, the
figures that only describe a climate (mean temperature, frost, rainfall, snow)
and the disagreement between the projection models.

## Order and period

Each ranking starts from the best end as defined by the criterion's
`rank_from_lowest` flag (low unemployment first, high living standard first);
`?ordre=inverse` flips it. Every row is compared at the latest period available
for the criterion across all unités urbaines, never at each unit's own latest.
Ties share a rank. Pages hold 50 rows (`?page=`).

## Median marker

A ranking page states the median of all unités urbaines for the criterion and
draws it as a tick on every row's bar, to read each value against a typical
unit. Bars are scaled on the largest value of the whole ranking, not of the
page, so they stay comparable from one page to the next.

## Explanatory note

Each ranking page opens with a short text in plain language: what is measured
(with an example when the criterion is not obvious), how to read it and its
limits, followed by the source in small print. Self-evident criteria such as
the population get one sentence. The text lives with the ranking in the catalog.

## Adding a ranking

Add the criterion code to the catalog with its theme, title, tagline and note: the
label, unit and direction come from the criterion itself.

To weigh several criteria at once, see `docs/personal-ranking.md`.

## Trend

Next to its value, a row shows how that unit moved between its first period and
the ranking's latest one, as on the [detail page](urban-unit-detail.md#trend)
(same calculation, same colors). The trend is the unit's own, so it does not
change with the sort order, and a unit with a single period has none.
