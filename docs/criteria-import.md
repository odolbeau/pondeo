# Criteria import

Each comparison criterion (`Criterion` + `ZoneCriterionValue`, see
`src/Entity/`) is fed by a dedicated `download`/`import` command pair under
`app:criteria:<name>:*`, following the same shape as the zones import (see
`docs/administrative-zones.md`). The download side is necessarily
bespoke per criterion (different source, different API shape), but the
import side is not: as long as the source CSV is one row = one (zone
code, period, value) tuple, the import command doesn't need its own
importer service — it describes the criterion (code, label, value type,
zone type, unit, CSV column names) and delegates to a shared, generic
importer. See `CriteriaPopulationImportCommand` for the reference example
of adding a criterion of this shape.

`ZoneCriterionValue` rows are versioned by `period` (a reference year, e.g.
an INSEE census year) — a zone can have several values for the same
criterion over time, never overwritten. Idempotent by construction: 4
partial unique indexes (`criterion_id, <zone_fk>_id, period`, one per zone
type, added by hand in a migration — see `ZoneCriterionValue`'s docblock)
let the shared importer upsert in batches rather than querying once per
row.

## Population

Source: INSEE's [Mélodi API](https://portail-api.insee.fr/), dataset
`DS_POPULATIONS_HISTORIQUES` (annual municipal population, `PMUN` measure,
2006-2023 — no authentication required). Vendored as
`resources/geo/population-historique.csv` (long format:
`code_insee,year,population`).

```
bin/console app:criteria:population:download   # refresh the vendored CSV
bin/console app:criteria:population:import      # upsert into the DB (all years at once)
```

Commune-level only for now — no EPCI/département/région aggregation yet
(would be a `SUM(...) GROUP BY` over member communes, same idea as
`ZoneImporter::computeEpciBoundaries()`'s `ST_Union`).
