# Criteria import

Each comparison criterion (`Criterion` + `ZoneCriterionValue`, see
`src/Entity/`) is fed by a dedicated `download`/`import` command pair under
`app:criteria:<name>:*`, following the same shape as the zones import (see
`docs/administrative-zones.md`).

`ZoneCriterionValue` rows are versioned by `period` (a reference year, e.g.
an INSEE census year) — a zone can have several values for the same
criterion over time, never overwritten. Idempotent by construction: 4
partial unique indexes (`criterion_id, <zone_fk>_id, period`, one per zone
type, added by hand in a migration — see `ZoneCriterionValue`'s docblock)
let an importer safely decide create vs. update per row.

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
