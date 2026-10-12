# Station access (car)

For each urban unit, the page lists its closest railway stations with the
car travel time and distance to each (see `docs/poi-stations.md` for the
stations). It is not a criterion: nothing is ranked or compared, it is a list.

## Rules

- A station **inside** the urban unit is listed as such, with no route. Up to
  3 are kept, the closest to the unit's centre first.
- Otherwise the routes start at the unit's **centre** (centroid, like the
  nuclear plant filter). The 3 stations closest as the crow flies are routed
  and listed **fastest first**, since the closest in a straight line is not
  always the quickest by road.
- A station for which no route is found is left out; a unit with none has no
  list.
- The year the routes were computed is stored with them.

Each listed station also shows its train connections to the major cities (see
`docs/station-journeys.md`).

## Source

Routes come from the IGN Géoplateforme routing service (`bdtopo-osrm`, car
profile, fastest route), built on the BD TOPO road network. It is free, needs
no key, and its data carries no storage or reuse restriction.

- **Times are theoretical**: BD TOPO speeds, no traffic, rather pessimistic in
  town. Good to compare stations and cities, not to plan a trip.
- **Islands**: a route may include a ferry, which makes the figure large.
- **Quota**: the service has no matrix endpoint, so computing costs one
  request per (unit, candidate station), about 7 000 in total. Requests are
  throttled and retried on rate limiting; a full run takes over an hour and
  happens only when stations or urban units change.

## Versioned data

The result is vendored under `resources/geo/` (Git LFS) like the other
reference data: one row per (unit, station) with its position, distance,
duration and year. Importing reads this file only, so rebuilding the database
(`make db-reset`) needs no network and no routing call.

## Refreshing

```
bin/console app:import:station-access --download
git diff resources/geo/
git add resources/geo/ && git commit
```

`--download` recomputes the file (the zones and stations must be imported
first), then imports it. The import replaces the previous links.
