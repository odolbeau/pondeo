# Seveso sites

The industrial sites classified Seveso in metropolitan France are stored as
points of interest (POIs), in two categories by level: "Site Seveso seuil
haut" (`seveso_high`) and "Site Seveso seuil bas" (`seveso_low`). The high
level is the one with the larger quantities of dangerous substances, hence the
stricter obligations. Both are children of the category "Site Seveso" (`seveso`), itself under "Site à risque" (`risk_site`) like the [nuclear plants](poi-nuclear-plants.md), so the detail page lists them under one heading.

## Source

Géorisques' "Établissements Seveso - France métropolitaine" layer (Ministry of
Ecological Transition, BRGM), a WFS service listed on
[data.gouv.fr](https://www.data.gouv.fr/datasets/etablissements-seveso-france-metropolitaine-3).
Terms: see [data-sources.md](data-sources.md).

- **Scope:** the sites in operation. Those flagged "En fin d'exploitation" are
  left out, as they are no longer a hazard to live next to. The overseas
  departments have their own layers and are not imported.
- **Details:** the main activity of the site (its NAF division, e.g.
  "Industrie chimique") is kept in the POI's `attributes`, and its postal
  address in the address.
- **Identity:** a site is matched on the source's national identifier, so a
  refresh updates it in place, and moves it to the other category if its level
  changed.
- **Not people:** these are industrial establishments, named after their
  company; there is nothing personal in the file.

The file is vendored under `resources/geo/` (Git LFS), like the other
reference data.

## Unité urbaine

Each site is attached to the urban unit whose boundary contains it, if any
(see `docs/urban-units.md`). A site outside every unit is kept, attached to
none.

## Urban unit page

The sites of the unit appear in its "Points d'intérêt" block, one list per
level: the count, plus a sample with the activity and the address (see
[urban-unit-detail.md](urban-unit-detail.md)).

## Refreshing

```
bin/console app:import:pois:seveso-sites --download
git diff resources/geo/
git add resources/geo/ && git commit
```

`--download` refreshes the vendored file, then imports it. The import is an
idempotent upsert that removes the sites the source no longer lists. Import
the zones first (`app:import:zones`); `make db-reset` does both in the right
order.
