# Nuclear power plants

The nuclear power plants of metropolitan France are stored as points of
interest (POIs) of the category "Centrale nucléaire" (`nuclear_power_plant`).
They are shown on the map and drive the "keep away from a plant" filter.

## Source

EDF's dataset
[Centrales de production nucléaire d'EDF SA](https://www.data.gouv.fr/datasets/centrales-de-production-nucleaire-dedf-sa)
on data.gouv.fr, under the Licence Ouverte.

- **Scope:** the 18 operating EDF sites of metropolitan France, one POI per
  site. The source lists one row per reactor; they are grouped by site.
- **Details:** each POI carries its reactor count and installed power (MW, the
  sum over its reactors) in its `attributes`, shown in the map tooltip.
- **Identity:** a plant is matched on the source's site name, so a refresh
  updates it in place.

The file is vendored under `resources/geo/` (Git LFS), like the other
reference data.

## Unité urbaine

Each plant is attached to the urban unit whose boundary contains it, if any
(see `docs/urban-units.md`). A plant outside every unit is kept, attached to
none.

## Map

- The plants are always drawn, with a radioactivity pictogram and a tooltip
  ("Le Blayais (4 réacteurs, 3704 MW)").
- The filter "Écarter les villes à moins de X km d'une centrale" removes the
  urban units whose **centre** (centroid) lies within X km of any plant, and
  draws a circle of that radius around each plant. Without a value, no circle
  is drawn and nothing is excluded.

## Refreshing

```
bin/console app:import:pois:nuclear-plants --download
git diff resources/geo/
git add resources/geo/ && git commit
```

`--download` refreshes the vendored file, then imports it. The import is an
idempotent upsert that removes the plants the source no longer lists. Import
the zones first (`app:import:zones`); `make db-reset` does both in the right
order.
