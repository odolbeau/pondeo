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
| Taux de pauvreté | Share of inhabitants living under 60 % of the national median standard of living, in % |

## Aggregation

Neither is additive, and a unit's true median can't be rebuilt from the
medians of its communes. Each criterion is the **average of the communes'
values weighted by their population** (same year): an approximation, close
when the communes are alike and rougher for a unit mixing very different
communes.

- INSEE withholds the figure of communes too small to protect privacy. They
  are left out of the average: the poverty rate, published for far fewer
  communes than the median, therefore rests on the larger communes of a unit.
- A unit where no commune has a figure has no value.
- Communes outside every urban unit are skipped.

## Refreshing

```
bin/console app:import:criteria:income --download
git diff resources/geo/
git add resources/geo/ && git commit
```

Without `--download`, the vendored file is imported as is. The import is an
idempotent upsert. It weighs communes with the vendored population series, so
import the zones first (`make db-reset` runs everything in the right order).
