# Tram and metro stations

The stations of the tram and metro lines in service in France are stored as
points of interest (POIs), one category per mode: "Station de tramway"
(`tram_stop`) and "Station de métro" (`metro_station`). They show whether an
urban unit has an urban rail network, and how many stations it has.

## Source

IGN's [BD TOPO](https://geoservices.ign.fr/bdtopo), layer "équipement de
transport", served by the Géoplateforme WFS service, under the Licence
Ouverte. It is the only national source found that tells the mode of a stop
and lists one object per station.

- **Scope:** objects of nature "Station de tramway" and "Station de métro"
  whose state is "En service". Stations under construction or planned are
  left out. A station without a name is left out too.
- **Platforms:** the layer draws each station as a few-metre polygon, and
  keeps one object per direction on some lines. Same-named stations of the
  same commune a few hundred metres apart are folded into one, at the mean of
  their positions.
- **Position:** the centre of the polygon, WGS84.
- **Identity:** a station is matched on the layer's own identifier (`cleabs`),
  so a refresh updates it in place, and moves it if its mode changed.

The file is vendored under `resources/geo/` (Git LFS), like the other
reference data.

## Not covered

- **Bus rapid transit (BHNS):** its stops are plain bus stops in every
  national source, in the GTFS feeds as in BD TOPO, which mark none as BHNS.
  Cerema's [Panorama BHNS](https://www.data.gouv.fr/datasets/recensement-des-reseaux-de-bus-a-haut-niveau-de-service)
  lists the lines but not their stops, so they are not imported.
- **Other transit stops:** regular bus stops, funiculars, cable cars and
  RER/Transilien stations (those are railway stations, see [poi-stations.md](poi-stations.md)).

## Unité urbaine

Each station is attached to the urban unit whose boundary contains it (see
`docs/urban-units.md`). A station outside every unit is kept, attached to
none.

## Detail page

The [urban unit detail](urban-unit-detail.md) lists them among its points of
interest, one block per mode with the count and a sample of stations.

## Refreshing

```
bin/console app:import:pois:transit-stops --download
git diff resources/geo/
git add resources/geo/ && git commit
```

`--download` refreshes the vendored file, then imports it. The import is an
idempotent upsert that removes the stations the source no longer lists. Import
the zones first (`app:import:zones`); `make db-reset` does both in the right
order.
