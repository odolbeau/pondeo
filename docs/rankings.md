# Rankings

Public pages that order the unités urbaines by one criterion: an index at
`/classements` (a top 3 per ranking, by theme) and one page per ranking at
`/classements/{criterion code}`. The home page features a few of them.

## Which criteria

Only criteria where "first" or "last" means something get a ranking: population
and its growth, density, unemployment and activity, living standard and
poverty, tax income, real-estate prices, vacant housing, commerce density,
higher-education share. Age brackets, socio-professional categories, tax
brackets and housing tenure are left out, and so are the non-comparable raw
counts.

## Order and period

Each ranking starts from the best end as defined by the criterion's
`rank_from_lowest` flag (low unemployment first, high living standard first);
`?ordre=inverse` flips it. Every row is compared at the latest period available
for the criterion across all unités urbaines, never at each unit's own latest.
Ties share a rank. Pages hold 50 rows (`?page=`).

## Adding a ranking

Add the criterion code to the catalog with its theme, title and tagline: the
label, unit and direction come from the criterion itself.
