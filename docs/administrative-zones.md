# Administrative zones — reference data

Files vendored under `resources/geo/` (committed to the repo, not
downloaded at import time — see `App\Command\ZonesImportCommand`), tracked
via [Git LFS](https://git-lfs.com/) (`.gitattributes`) since they run tens
of MB and are replaced wholesale on every refresh — keeps the checked-out
repository itself small regardless of how much reference data (zones,
criteria) accumulates over time. Requires `git-lfs` installed locally (see
`bin/doctor`) and `git lfs install` run once per machine; a plain `git
clone` then fetches the real file content transparently.

- `regions.geojson`, `departements.geojson`, `communes.geojson` —
  boundaries (France métropolitaine only, no overseas territories),
  simplified variant of
  [france-geojson](https://github.com/gregoiredavid/france-geojson) (IGN
  Admin Express / INSEE COG data, open licence). Properties: `code` (INSEE
  code), `nom`.
- `communes-epci.csv` — commune → EPCI correspondence table (relevant
  columns: `code_insee`, `typecom` [COM/ARM], `epci_code` [SIREN],
  `epci_nom`), extracted from the data.gouv.fr dataset
  ["Communes et villes de France"](https://www.data.gouv.fr/datasets/communes-et-villes-de-france-en-csv-excel-json-parquet-et-feather).

## Staleness

This data is republished roughly once a year (commune mergers, EPCI
reorganizations, Code Officiel Géographique updates). A vendored file does
not refresh itself.

## Refreshing

```
bin/console app:zones:download
git diff resources/geo/
git add resources/geo/ && git commit
```

Then re-run `bin/console app:zones:import` to apply the changes to the
database (idempotent upsert, never duplicates).

## Dev flow

`make db-reset` (and therefore `make install`) does everything
automatically: schema, real zones import (~1-2 min), then demo fixtures
(`bin/reset-db`). `AppStory` (demo POI/criteria) relies on real communes
(Massy/Palaiseau) already being imported at that point.

To re-run only the import (e.g. after `app:zones:download`), without a
full reset:

```
bin/console app:zones:import                                 # idempotent upsert, never duplicates
bin/console foundry:load-fixtures --no-interaction --append  # --append: does not reset the database
```

`--append` is required here: without it, `foundry:load-fixtures` would
reset the database and wipe the zones just imported (which is why
`bin/reset-db` also uses it internally, after running `app:zones:import`).
