# Housing

Each urban unit (see `docs/urban-units.md`) carries three shares describing
its housing stock, under the "Logement" group of its detail page (see
`docs/urban-unit-detail.md`), with the usual rank, median of all urban units
and evolution chart.

## Source

INSEE's census, "Logements" dataset, through the
[Mélodi API](https://portail-api.insee.fr/) (dataset `DS_RP_LOGEMENT_PRINC`,
no authentication, open licence). Figures exist for the **census years only**
(2012, 2017, 2023).

## Criteria

| Criterion | Meaning |
|---|---|
| Part de propriétaires | Main residences occupied by their owner, over all main residences, in % |
| Part de logement social | Main residences rented in social housing (HLM), over all main residences, in % |
| Part de logements vacants | Vacant dwellings, over all dwellings (main, secondary and vacant), in %. Ranked from the lowest: the 1st unit is the one with the fewest vacant dwellings |

The first two are shares of **main residences** (where people actually live);
secondary homes and vacant dwellings are out of their base. The third has all
dwellings as base, so a seaside town full of second homes shows it as low
vacancy but few main residences.

- **Counts:** summed over the communes of each urban unit (communes outside
  every urban unit are skipped). They are only a means to the shares and are
  not kept.
- **Shares:** derived from those sums, never summed. A unit without a base has
  no share.

## Refreshing

```
bin/console app:import:criteria:housing --download
git diff resources/geo/
git add resources/geo/ && git commit
```

The download is the slowest one (several minutes: the dataset is wide and
paged finely). Without `--download`, the vendored file is imported as is. The
import is an idempotent upsert. Import the zones first (`make db-reset` runs
everything in the right order).
