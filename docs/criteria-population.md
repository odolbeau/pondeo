# Population dynamics

Under the "Démographie" group of the urban unit detail page, next to the
population itself, two criteria describe how a unit evolves. Like every
criterion they get a rank, the median of all urban units and a small curve.

| Criterion | Meaning |
|---|---|
| Évolution sur 5 ans | Population change over the 5 years ending at the period, in % |
| Densité | Inhabitants per km² of the unit's boundary |

## Aggregation

These are ratios, so they are never summed across communes: they are derived
from the stored urban unit population and boundary, right after the population
import (`app:import:criteria:population`). Re-running the import refreshes them.

- Growth is only defined where the earlier year exists: nothing before 2011.
- There is deliberately no "average growth since 2006": its curve would plot a
  running average that converges over time, which reads as a trend it is not.
- Urban units are fixed at the current zoning, so the series is comparable
  across years (see `docs/criteria-import.md`).
- The density uses the area of the whole unit, built-up and non built-up land
  of its communes alike, and the same area for every year.
