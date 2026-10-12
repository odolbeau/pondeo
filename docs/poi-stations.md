# Railway stations

The passenger railway stations of France are stored as points of interest
(POIs) of the category "Gare ferroviaire" (`railway_station`). They are what a
"distance to the nearest station" comparison is computed from.

## Source

SNCF Gares & Connexions, dataset
[Gares de voyageurs](https://www.data.gouv.fr/fr/datasets/gares-de-voyageurs-1/)
on data.gouv.fr, under the
[ODbL](https://opendatacommons.org/licenses/odbl/) licence: reuse must credit
SNCF and share derived databases under the same terms.

- **Scope:** passenger stations only (~2 800). Freight-only stations are not
  included; SNCF Réseau's "Liste des gares" covers them, but mixes freight and
  passenger, lists a station once per line, and gives coordinates projected
  onto the track rather than the station itself.
- **Coordinates:** WGS84, as published by the station operator. The source
  writes them latitude first; they are stored as a longitude/latitude point
  like every other POI.
- **Identity:** each station is matched on the source's own station id, so a
  refresh updates a station in place, renames included. A station can carry
  several UIC codes (a station complex); they are kept in the vendored file
  but are not the key.

The file is vendored under `resources/geo/` (Git LFS), like the other
reference data.

## Unité urbaine

Each station is attached to the urban unit whose boundary contains it (see
`docs/urban-units.md`). A station outside every urban unit (~25 %, mostly
rural stations) is kept, attached to none: it still counts as the nearest
station for the units around it.

## Refreshing

```
bin/console app:import:pois:stations --download
git diff resources/geo/
git add resources/geo/ && git commit
```

`--download` refreshes the vendored file, then imports it. The import is an
idempotent upsert: it never duplicates, and removes the
stations the source no longer lists. Import the zones first
(`app:import:zones`); `make db-reset` does both in the right order.

## Map filter

"Exclure les villes sans gare" keeps only the urban units that contain
at least one station. Without it, every unit is shown. See
`docs/criteria-station-access.md` for the stations *near* a unit.
