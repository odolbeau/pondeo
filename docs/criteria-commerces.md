# Shops

Each urban unit (see `docs/urban-units.md`) carries the number of shops it
holds and their density per 1000 inhabitants, under the "Commerces" group of
its detail page (see `docs/urban-unit-detail.md`). Like every criterion, they
get a rank and the median of all urban units, to place a city.

On the detail page the counts have no row of their own: each density is shown
with its count in the title, e.g. "Commerces (196)", the density as the value,
and the unit's rank and the median next to it. The counts are not ranked.

## Source

INSEE's Base permanente des équipements (BPE), domain "Commerce", through the
[Mélodi API](https://portail-api.insee.fr/) (dataset `DS_BPE`, no
authentication, open licence). Vintage 2025 (situation on 1 January).

The BPE counts **establishments** open to the public, not floor area or
turnover: a hypermarket and a newsagent each count for one. It is the
reference for comparing how well-equipped places are, not a retail census.

## Criteria

| Count | Density per 1000 inhabitants |
|---|---|
| All shops (the sum of the three below, fuel and EV charging stations included, as INSEE does) | yes |
| Large stores (hypermarkets, supermarkets, DIY superstores) | yes |
| Food shops | yes |
| Non-food shops | yes |

The detail by shop type (bakery, bookshop…) is deliberately not imported.

- **Count:** summed over the communes of each urban unit; communes outside
  every urban unit are skipped.
- **Density:** count divided by the unit's population, times 1000. It is
  computed from the stored values, never summed. The population used is the
  latest year not after the count's: the census series ends before the BPE
  does, so the 2025 counts are divided by the 2023 population. A unit without
  population has no density.

## Map filter

The map (`/carte`) can require a minimum density of shops, all categories
together. It is a lower bound only; leaving it empty disables the filter. A
unit without a density never matches a minimum. The value is kept in the map
URL like the other filters.

## Refreshing

```
bin/console app:import:criteria:commerces --download
git diff resources/geo/
git add resources/geo/ && git commit
```

Without `--download`, the vendored file is imported as is. The import is an
idempotent upsert. Import the zones and the population first
(`make db-reset` runs everything in the right order).
