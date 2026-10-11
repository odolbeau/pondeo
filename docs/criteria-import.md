# Criteria import

Each comparison criterion is fed by a dedicated `app:import:criteria:<name>`
command, following the same shape as the other imports (see
`docs/urban-units.md`): it imports the vendored CSV, and its `--download`
option refreshes that CSV from the source first. Downloading is necessarily
bespoke per criterion (different source, different API shape), but importing
is not: as long as the source CSV is one row = one (commune code, period,
value) tuple, the command doesn't need its own importer — it describes the
criterion (code, label, value type, unit, CSV column names) and delegates to
the shared, generic importer. `app:import:criteria:population` is the
reference example of adding a criterion of this shape.

## Groups

A criterion belongs to a display group ("Démographie", "Climat", ...), set by
the import command through the spec's `groupLabel`. The urban unit detail page
lists criteria section by section under these groups; a criterion without one
lands in "Autres". Re-running an import brings an already-existing criterion's
group in line with its spec.

## Stored per urban unit

Values are stored at the **urban unit** level only, never per commune. The
source data is keyed by commune, so the importer sums the communes of each
urban unit; communes outside every urban unit are skipped. This only makes
sense for additive criteria (population is a straight sum): the importer
rejects a non-additive criterion (an average temperature, a text label) rather
than silently summing it. The first such criterion will need its own
aggregation rule, decided then.

## Versioning and idempotency

Values are versioned by `period` (a reference year, e.g. an INSEE census
year): an urban unit can have several values for the same criterion over time,
never overwritten. There is one value per (criterion, urban unit, period), and
the import is an idempotent upsert — re-running it updates values in place and
never duplicates.

## Population

Source: INSEE's [Mélodi API](https://portail-api.insee.fr/), dataset
`DS_POPULATIONS_HISTORIQUES` (annual municipal population, `PMUN` measure,
2006-2023 — no authentication required). Vendored as
`resources/geo/population-historique.csv` (long format:
`code_insee,year,population`).

```
bin/console app:import:criteria:population --download   # refresh the vendored CSV, then import
bin/console app:import:criteria:population               # import only: upsert into the DB, summed per urban unit
```

Because urban units are fixed at the current zoning, the historical series
uses today's perimeters throughout, so years are comparable with each other.

The same command also derives growth and density from the stored population:
see [criteria-population.md](criteria-population.md).

## Shops

See [criteria-commerces.md](criteria-commerces.md): same shape, with a derived density per 1000 inhabitants.

## Real estate prices

See [criteria-real-estate.md](criteria-real-estate.md): not additive, so averaged per urban unit with the number of sales as weight.

## Employment

See [criteria-employment.md](criteria-employment.md): counts are summed, rates derived from them; census years only.

## Income

See [criteria-income.md](criteria-income.md): not additive, so averaged per urban unit with the population as weight.

## Education

See [criteria-education.md](criteria-education.md): counts are summed, the share derived from them; census years only.
