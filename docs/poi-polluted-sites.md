# Polluted sites and polluting establishments

Two kinds of sites that are a nuisance to live near are stored as points of
interest (POIs), under the "Site à risque" category (see
[poi-categories.md](poi-categories.md)), next to the
[Seveso sites](poi-seveso-sites.md) and the [nuclear plants](poi-nuclear-plants.md):

- **Sites pollués** (`polluted_soil_site`): the "secteurs d'information sur les
  sols" (SIS), the sites the State has classified as polluted, which restricts
  what can be built there. The sites still under instruction are left out: only
  the classified ones are kept.
- **Établissements pollueurs** (`polluting_plant`): the industrial
  establishments that declare emissions of pollutants into the air, water or
  soil, from the national register of polluting emissions (IREP).

## Source

Géorisques (Ministry of Ecological Transition, BRGM), two layers of its WFS
service: "Secteurs d'information sur les sols" and "Établissements
polluants". Terms: see [data-sources.md](data-sources.md).

- **Sites pollués:** name, address and position. A site with no name is shown as
  "Site pollué".
- **Établissements pollueurs:** the source only gives a name, a position and a
  postal code, which stands for the address. No activity is shown.
- **Not people:** these are sites and companies; there is nothing personal in
  the files.

The files are vendored under `resources/geo/` (Git LFS), like the other
reference data.

## Unité urbaine

Each POI is attached to the urban unit whose boundary contains it, if any (see
`docs/urban-units.md`), and one outside every unit is kept, attached to none.
They show on the detail page of the unit, among the points of interest (see
[urban-unit-detail.md](urban-unit-detail.md)).

## Refreshing

```
bin/console app:import:pois:polluted-sites --download
bin/console app:import:pois:polluting-plants --download
git diff resources/geo/
git add resources/geo/ && git commit
```

`--download` refreshes the vendored file, then imports it. The imports are
idempotent upserts that remove the POIs the source no longer lists. Import the
zones first (`app:import:zones`); `make db-reset` does it all in the right order.
