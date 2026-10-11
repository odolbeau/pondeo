# Age structure

Each urban unit (see `docs/urban-units.md`) carries the share of seven age
brackets in its population, under the "Âge" group of its detail page (see
`docs/urban-unit-detail.md`), with the usual rank, median of all urban units
and evolution chart. For the age and sex breakdown of one unit at a glance,
see [population-pyramid.md](population-pyramid.md).

## Source

INSEE's census, "Évolution et structure de la population" dataset, through the
[Mélodi API](https://portail-api.insee.fr/) (dataset `DS_RP_POPULATION_PRINC`,
no authentication, open licence). Figures exist for the **census years only**
(2012, 2017, 2023).

## Criteria

Seven brackets that do not overlap, so their shares add up to 100 %:

| Bracket |
|---|
| Moins de 15 ans |
| 15 à 24 ans |
| 25 à 39 ans |
| 40 à 54 ans |
| 55 à 64 ans |
| 65 à 79 ans |
| 80 ans ou plus |

The dataset also offers overlapping brackets (under 20, 20-64, 65 and over):
they are deliberately left out, as they would only repeat the others.

Ranking a bracket says little on its own: a high share of 15-24 year-olds is
a student city, a high share of 65 and over a retirement one, and the shares
move against each other. The evolution chart is the useful part, it shows a
unit getting older or younger.

- **Counts:** summed over the communes of each urban unit (communes outside
  every urban unit are skipped). They are only a means to the shares and are
  not kept.
- **Shares:** derived from those sums, never summed. A unit without
  population has no share.

## Refreshing

```
bin/console app:import:criteria:age-structure --download
git diff resources/geo/
git add resources/geo/ && git commit
```

Without `--download`, the vendored file is imported as is. The import is an
idempotent upsert. Import the zones first (`make db-reset` runs everything in
the right order).
