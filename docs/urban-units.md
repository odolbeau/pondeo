# Urban units — reference data

The app compares **agglomérations**, which INSEE calls *unités urbaines*
(urban units). Communes, EPCIs, départements and régions are not units of
comparison: only urban units are stored with their criteria values.

## What an urban unit is

A group of one or more neighbouring communes forming a continuous built-up
area (no gap over 200 m between buildings) with at least 2 000 inhabitants.
Vannes is the typical example: ~56 k inhabitants in the commune, ~84 k in the
urban unit (Vannes, Saint-Avé, Séné, Plescop).

- An urban unit is a set of **whole communes**. Its population is the sum of
  its communes' populations; its boundary is the union of their boundaries.
- A single commune can be an urban unit on its own (a "ville isolée").
- Communes belonging to no urban unit (~27 000 rural communes, ~21 % of the
  population) are **out of scope**: they are not imported, and a POI located
  there is attached to nothing.
- The unit is named after its main commune. The UI should say "Unité urbaine
  de Vannes" so it is never confused with the commune.

## Not the same as an EPCI

An EPCI (intercommunalité) is a political grouping of communes for shared
competencies; an urban unit is a physical notion. They overlap without
matching: only a handful of urban units coincide with an EPCI, many span
several EPCIs (Paris: 27) and some span several départements or régions. So
EPCIs cannot stand in for urban units, and are not modelled at all.

## Départements and régions

Not tables: they are the `Departement` and `Region` enums. They change only
with a territorial reform, which is handled by hand in code. Each commune
stores its département; the région is derived from it. An urban unit stores
the département of its most populous commune, which is enough for display and
coarse filtering.

## Source files

Vendored under `resources/geo/` (committed, not downloaded at import time),
tracked via [Git LFS](https://git-lfs.com/) (`.gitattributes`). Requires
`git-lfs` installed locally (see `bin/doctor`) and `git lfs install` run once
per machine; a plain `git clone` then fetches the real content transparently.

- `communes-unites-urbaines.csv` — one row per commune of an urban unit
  (France métropolitaine only), with the unit's code and name. Extracted from
  the data.gouv.fr dataset
  ["Communes et villes de France"](https://www.data.gouv.fr/datasets/communes-et-villes-de-france-en-csv-excel-json-parquet-et-feather),
  which carries INSEE's urban-unit zoning.
- `communes.geojson` — boundaries of those communes, simplified variant of
  [france-geojson](https://github.com/gregoiredavid/france-geojson) (IGN
  Admin Express, open licence).

INSEE publishes no polygon for urban units: the import computes them as the
union of their communes' boundaries.

### Known gaps

The two sources are not perfectly aligned: a few communes (recent mergers)
have no polygon, so their urban unit is slightly smaller on the map — and an
urban unit made of only such communes has no boundary and is absent from it.
Its criteria values are unaffected.

## Staleness

INSEE revises the urban-unit zoning about once per census cycle, and communes
merge every year. A vendored file does not refresh itself.

## Refreshing

```
bin/console app:import:zones --download
git diff resources/geo/
git add resources/geo/ && git commit
```

`--download` refreshes the vendored files, then imports them: the database
gets the changes in the same run (idempotent upsert; communes and urban units
the source no longer lists are removed). Without the option, the command only
imports what is already vendored.

## Dev flow

`make db-reset` (and therefore `make install`) does everything
automatically: schema, zones import, population import, then demo fixtures
(`bin/reset-db`). `AppStory` (demo POI/criteria) relies on the real Vannes and
Nantes urban units already being imported at that point.

To re-run only the import, without a full reset:

```
bin/console app:import:zones                                 # idempotent upsert, never duplicates
bin/console foundry:load-fixtures --no-interaction --append  # --append: does not reset the database
```

`--append` is required here: without it, `foundry:load-fixtures` would
reset the database and wipe the zones just imported (which is why
`bin/reset-db` also uses it internally, after running `app:import:zones`).
