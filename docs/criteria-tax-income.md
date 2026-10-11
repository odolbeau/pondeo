# Tax income

Besides the median standard of living and the poverty rate (see
[criteria-income.md](criteria-income.md)), each urban unit (see
`docs/urban-units.md`) carries the distribution of its tax households' income,
under the "Revenus fiscaux" group of its detail page (see
`docs/urban-unit-detail.md`), with the usual rank and median of all urban
units.

## Source

The DGFiP's income tax by commune ("IRCOM"), published on
[data.gouv.fr](https://www.data.gouv.fr/datasets/limpot-sur-le-revenu-par-collectivite-territoriale-ircom)
under an open licence, one file per income year. The latest year is kept (a
single year, so there is no evolution chart).

The figures come from the income tax returns, not from a survey: they are
exact counts of **tax households** (a couple filing together is one), and the
income is the *revenu fiscal de référence*, before tax. A tax household is not
a person nor a household in the census sense, and the income of the people who
do not file a return (or file a zero one) is not in it. For an income per
consumption unit, after tax and benefits, use the median standard of living of
[criteria-income.md](criteria-income.md).

## Criteria

| Criterion | Meaning |
|---|---|
| Revenu fiscal moyen par foyer | Reference taxable income of all the tax households of the unit, divided by their number, in € |
| Foyers à moins de 15 000 € | Share of tax households whose reference income is under 15 000 €, in % |
| Foyers de 15 000 à 30 000 € | idem |
| Foyers de 30 000 à 50 000 € | idem |
| Foyers de 50 000 à 100 000 € | idem |
| Foyers à plus de 100 000 € | idem |

The five brackets do not overlap, so their shares add up to 100 %. A mean
hides the distribution: the last bracket tells about wealthy households, the
first about the poorest.

- **Counts:** the households and their income are summed over the communes of
  each urban unit (communes outside every urban unit are skipped). They are
  only a means to the figures above and are not kept.
- **Criteria:** derived from those sums, never summed or averaged, so they are
  exact for the whole unit. A unit without households has none.
- **Breakdown by bracket:** the DGFiP publishes it only for the communes with
  enough tax households (a sixth of them, the largest): the mean income is
  known for every unit, but the shares rest on the communes with a breakdown
  alone, with their own total of households as base, so they always add up to
  100 %. A unit made of a large commune and small ones therefore gets the
  distribution of its large one; a unit of small communes only has no shares.

## Refreshing

```
bin/console app:import:criteria:tax-income --download
git diff resources/geo/
git add resources/geo/ && git commit
```

Without `--download`, the vendored file is imported as is. The import is an
idempotent upsert. Import the zones first (`make db-reset` runs everything in
the right order).
