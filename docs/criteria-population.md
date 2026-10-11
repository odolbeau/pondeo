# Population dynamics

Under the "Démographie" group of the urban unit detail page, next to the
population itself, two criteria describe how a unit evolves. Like every
criterion they get a rank, the median of all urban units and a chart of their
evolution.

| Criterion | Meaning |
|---|---|
| Évolution annuelle moyenne depuis 2006 | Compound yearly growth rate between the first year of the series and the period, in %/year |
| Densité | Inhabitants per km² of the unit's boundary |

## Aggregation

These are ratios, so never summed across communes: they are derived from the
stored urban unit population and boundary, right after the population import
(`app:import:criteria:population`). Re-running the import refreshes them.

- The growth of a year is the average rate since 2006, so the first year has
  none, and the early years rest on very few years of data: the curve is
  noisy at the start and converges afterwards. It is the evolution of the
  average rate, not of the population.
- The density uses the area of the whole unit, built-up and non built-up land
  of its communes alike, and the same area for every year.
- A unit without boundary has no density.
- Urban units are fixed at the current zoning, so the series is comparable
  across years (see `docs/criteria-import.md`).
