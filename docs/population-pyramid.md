# Population pyramid

The detail page of an urban unit (see `docs/urban-unit-detail.md`) shows, under
its criteria, the population of the unit by sex and age as a pyramid: men
(blue) on the left, women (pink) on the right, one row per age bracket
from the oldest at the top to the youngest. A
graduated axis gives the number of inhabitants, hovering a bar gives its exact
count, and the share of each sex is written above. Both sides share the same
scale, so the shape of the pyramid is not distorted.

Under the pyramid, a folded table gives the same figures as numbers, youngest
bracket first: men, women, total and share of the population of each bracket,
with the totals.

The brackets are five years wide (0-4 … 90-94, then 95 and over) or ten
(0-9 … 80-89, then 90 and over):

- **Full page:** both are available, five years by default, with a switch that
  changes the width without reloading the page.
- **Map panel:** ten years only, no switch: the panel is narrow and a coarser
  pyramid is more legible there.

A unit without data has no pyramid section.

## Source

INSEE's census, "Population détaillée par sexe et âge" (POP1), through the
[Mélodi API](https://portail-api.insee.fr/) (dataset
`DS_RP_TD_POPULATION_AGESEX_PRINC`, no authentication, open licence). Figures
exist for the **latest census year only** (2023), at the age of one year: they
are grouped into five-year brackets, which is as precise as a pyramid reads.

INSEE publishes this dataset for the urban units themselves, so the figures are
INSEE's, not summed from communes, and match the population of the unit
(see `docs/criteria-population.md`), except for a handful of units (four in
2023) whose perimeter differs between INSEE's dataset and the app's: the
pyramid then shows INSEE's population, not the one of the "Population"
criterion. The API can't filter several urban units
at once, hence one request per unit.

This is not a ranked criterion: it is a distribution, shown as is. For ranked,
comparable figures on the age of a unit, see
[criteria-age-structure.md](criteria-age-structure.md).

## Refreshing

```
bin/console app:import:age-pyramid --download
git diff resources/geo/
git add resources/geo/ && git commit
```

The download is the slowest of the project (over an hour: one throttled request
per urban unit). Without `--download`, the vendored file is imported as is. The
import is idempotent, and `make db-reset` runs it with the others.
