# Income

Each urban unit (see `docs/urban-units.md`) carries the median standard of
living and the poverty rate of its inhabitants, under the "Revenus" group of
its detail page (see `docs/urban-unit-detail.md`), with the usual rank and
median of all urban units.

## Source

INSEE's Filosofi (tax and social data), through the
[Mélodi API](https://portail-api.insee.fr/) (dataset `DS_FILOSOFI_CC`, no
authentication, open licence). A single year (2023), so there is no evolution
chart.

## Criteria

| Criterion | Meaning |
|---|---|
| Niveau de vie médian | Yearly disposable income per consumption unit: half of the inhabitants live on less, in €/year |
| Taux de pauvreté | Share of inhabitants living under 60 % of the national median standard of living, in %. Ranked from the lowest: the 1st unit is the one with the least poverty |

## Aggregation

INSEE publishes both figures for the urban units themselves, so they are
stored as is: they are the true median and rate of the whole unit, not an
average of its communes' (a median can't be rebuilt from the medians of its
parts).

- INSEE withholds the figure of a unit when it is too small to protect
  privacy: a few units, mostly the smallest, have no poverty rate. A unit
  without a figure has no value.
- For a finer view of the incomes (brackets, mean), see
  [criteria-tax-income.md](criteria-tax-income.md). INSEE's deciles, Gini index
  and income breakdown only exist for the départements and above, never for a
  commune or an urban unit.

## Refreshing

```
bin/console app:import:criteria:income --download
git diff resources/geo/
git add resources/geo/ && git commit
```

Without `--download`, the vendored file is imported as is. The import is an
idempotent upsert. Import the zones first (`make db-reset` runs everything in
the right order).
