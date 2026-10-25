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
source data is usually keyed by commune, so the importer combines the communes
of each urban unit according to the criterion's aggregation; communes outside
every urban unit are skipped.

| Aggregation | Result | Example |
|---|---|---|
| Sum (default) | total of the communes' values | population |
| Weighted mean | average of the communes' values weighted by a weight column; a unit whose total weight is zero gets no value | price per m², weighted by the number of sales |
| Max | highest of the communes' values | |

When the source already publishes its figures per urban unit (the CSV is keyed
by urban unit code instead of commune code), there is nothing to combine and
the values are stored as is.

Aggregating only applies to numeric criteria: a text label or a flag is
rejected rather than silently combined.

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

See [criteria-commerces.md](criteria-commerces.md) and [equipements-bpe.md](equipements-bpe.md): same shape, with a derived density per 1000 inhabitants.

## Associations

See [criteria-associations.md](criteria-associations.md): same shape, with a derived density per 1000 inhabitants.

## Real estate prices

See [criteria-real-estate.md](criteria-real-estate.md): not additive, so averaged per urban unit with the number of sales as weight.

## Rents

See [criteria-rents.md](criteria-rents.md): not additive, so averaged per urban unit with the number of private rentals (census) as weight.

## Employment

See [criteria-employment.md](criteria-employment.md): counts are summed, rates derived from them; census years only.

## Income

See [criteria-income.md](criteria-income.md): not additive, so taken from INSEE's own figures for each urban unit.

## Education

See [criteria-education.md](criteria-education.md): counts are summed, the share derived from them; census years only.

## Housing

See [criteria-housing.md](criteria-housing.md): counts are summed, the shares derived from them; census years only.

## Age structure

See [criteria-age-structure.md](criteria-age-structure.md): counts are summed, the shares derived from them; census years only.

## Socio-professional categories

See [criteria-pcs.md](criteria-pcs.md): counts are summed, the shares derived from them; census years only.

## Tax income

See [criteria-tax-income.md](criteria-tax-income.md): counts are summed, the figures derived from them; a single income year.

## Risks

See [criteria-risks.md](criteria-risks.md): flags and counts per commune, averaged with population as weight (the seismic zone: highest).

## Climate

See [criteria-climate.md](criteria-climate.md): 1991-2020 normals per urban unit, from Open-Meteo.

## Climate projections

See [criteria-climate-projections.md](criteria-climate-projections.md): computed per urban unit from the Open-Meteo Climate API, by a resumable download; stored with the reference year 2050.
