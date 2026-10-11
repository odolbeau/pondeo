# Socio-professional categories

Each urban unit (see `docs/urban-units.md`) carries the share of each
socio-professional category in its population, under the "Catégories
socioprofessionnelles" group of its detail page (see
`docs/urban-unit-detail.md`), with the usual rank, median of all urban units
and evolution chart.

## Source

INSEE's census, "Évolution et structure de la population" dataset, through the
[Mélodi API](https://portail-api.insee.fr/) (dataset `DS_RP_POPULATION_COMP`,
no authentication, open licence). Figures exist for the **census years only**
(2012, 2017, 2023).

## Criteria

The census groups every person aged 15 or more in one of eight categories that
do not overlap, so the shares add up to 100 %:

| Category |
|---|
| Agriculteurs exploitants |
| Artisans, commerçants, chefs d'entreprise |
| Cadres et professions intellectuelles supérieures |
| Professions intermédiaires |
| Employés |
| Ouvriers |
| Retraités |
| Autres sans activité professionnelle |

On the detail page the categories are not listed as eight rows but drawn as one
chart (see `docs/urban-unit-detail.md`): a horizontal bar per category gives the
share of the unit, and a dashed vertical mark the median of all urban units, so
the profile of the unit stands out against a typical one. The categories keep
their order of the census, not sorted by size. Each of them still is a
criterion, with its rank and median, shown on hovering a bar.

The base is the whole population aged 15 or more, not only the working one: a
retired or student-heavy city shows it in the first place, and the shares of
the working categories are lower where there are more retirees. For the
unemployment or activity of the working-age population, see
[criteria-employment.md](criteria-employment.md).

The last category gathers everyone without a profession who is not retired:
students, people at home, people who have never worked. It is not
unemployment.

- **Counts:** summed over the communes of each urban unit (communes outside
  every urban unit are skipped). They are only a means to the shares and are
  not kept.
- **Shares:** derived from those sums, never summed. A unit without
  population has no share.

## Refreshing

```
bin/console app:import:criteria:pcs --download
git diff resources/geo/
git add resources/geo/ && git commit
```

Without `--download`, the vendored file is imported as is. The import is an
idempotent upsert. Import the zones first (`make db-reset` runs everything in
the right order).
