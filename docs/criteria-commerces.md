# Shops

Each urban unit (see `docs/urban-units.md`) carries the number of shops it
holds and their density per 1000 inhabitants, under the "Commerces" group of
its detail page (see `docs/urban-unit-detail.md`). Like every criterion, they
get a rank and the median of all urban units, to place a city, and one value
per year of the source, which gives the evolution chart.

On the detail page the counts have no row of their own: each density is shown
with its count in the title, e.g. "Commerces (196)", the density as the value,
and the unit's rank and the median next to it. The counts are not ranked.

## Source

INSEE's Base permanente des équipements (BPE), "BPE en évolution" series,
through the [Mélodi API](https://portail-api.insee.fr/) (dataset
`DS_BPE_EVOLUTION`, no authentication, open licence). It is published per
urban unit for the situation on 1 January of **2015, 2020 and 2025**, and only
for the equipment types INSEE keeps comparable over time.

The shops are the commerce domain of the facility file, which holds the other
domains too (see [equipements-bpe.md](equipements-bpe.md)).

The BPE counts **establishments** open to the public, not floor area or
turnover: a hypermarket and a newsagent each count for one. It is the
reference for comparing how well-equipped places are, not a retail census.

### Why this series and not the full BPE

The full BPE (`DS_BPE`) holds the latest year only, whereas the evolution
series gives three years to compare. Using it for every year keeps the
counts comparable from one year to the next.

The difference is that the series leaves out **EV charging stations** (type
B326), which did not exist in 2015. They are therefore not counted in any
year, unlike the full BPE; service stations are. They weigh about 5% of the
shops of the full BPE for 2025.

## Shop types

The file keeps the 34 shop types of the series (bakery, bookshop, clothes
shop…). Three families are derived from them:

| Family | Types |
|---|---|
| Large stores | hypermarkets, supermarkets, DIY superstores |
| Food shops | the other shops of the BPE's food sub-domain (butchers, bakeries…) |
| Non-food shops | the other shops of the BPE's non-food sub-domain (clothes, books, service stations…) |

## Criteria

| Count | Density per 1000 inhabitants |
|---|---|
| All shops (the sum of the three families) | yes |
| Large stores | yes |
| Food shops | yes |
| Non-food shops | yes |

- **Count:** the sum of the types of the family. Only the types are stored
  per urban unit in the source file: a family is derived on import.
- **Density:** count divided by the unit's population, times 1000. It is
  computed from the stored values, never summed. The population used is the
  latest year not after the count's. A unit without population has no
  density.
- **Missing rows:** the source does not list a unit and type when it has none
  (or the figure is under statistical secrecy): the count is then taken as
  zero.

## Detail by type

The urban unit detail page lists the shops by type in a collapsed block under
the "Commerces" group: one row per type, grouped by family, most numerous
first, with the count of each year and the change over the whole series. The
block has no rank and no median, unlike the criteria: the count of a single
type is too small in most urban units for a comparison to mean anything. For
the same reason the change is given as a percentage only when the type had at
least ten shops at the start.

## Evolution

The three years allow reading a trend: a falling number of shops over ten
years is a sign of a declining town centre. Read with care:

- It counts establishments, not vacant premises. Fewer shops can also mean
  fewer, larger stores.
- Some units show extreme changes: in one unit the clothes shops went from 4
  to 75 in ten years, which looks like a large shopping centre or a change in
  the survey rather than a trend of the town. They are kept as published.

## Map filter

The map (`/carte`) can require a minimum density of shops, all categories
together, at the latest year. It is a lower bound only; leaving it empty
disables the filter. A unit without a density never matches a minimum. The
value is kept in the map URL like the other filters.

## Refreshing

```
bin/console app:import:criteria:commerces --download
git diff resources/geo/
git add resources/geo/ && git commit
```

Without `--download`, the vendored file is imported as is. The import is an
idempotent upsert. Import the zones and the population first
(`make db-reset` runs everything in the right order).
