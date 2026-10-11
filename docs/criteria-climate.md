# Climate criteria

Twelve criteria in the "Climat" group, describing the typical climate of each
urban unit: the 1991-2020 normals. Same shape as the other criteria (see
`criteria-import.md`): one value per urban unit, stored with `period = 2020`
(the end of the normals period).

## Source

[Open-Meteo](https://open-meteo.com/) historical weather API (ERA5 reanalysis
family), free for non-commercial use, licensed CC BY 4.0. The attribution
"Données météo : Open-Meteo.com (CC BY 4.0)" is displayed on the home page and
under the "Climat" group of the urban unit page; keep it wherever the figures
are shown.

The API is quota-limited (about 600 calls per minute, 5 000 per hour, 10 000
per day) and a request is weighted by its number of locations, variables and
days: a 30-year daily request for 8 variables weighs about 600 calls per
location. This is why the figures are computed once and vendored, never
fetched at runtime.

## Location

One point per urban unit: its centroid. Centroids falling in the same 0.1°
cell (the resolution of the ERA5-Land grid) share a single location, which
removes a fifth of the requests. Several locations are sent per request.

## Derivations

Each measure is derived per calendar year from the daily series, then averaged
over the 30 years.

| Criterion | Per year |
|---|---|
| Température moyenne | mean of the daily mean temperature |
| Jours > 30 °C / > 35 °C | days with a maximum above the threshold |
| Nuits tropicales | days with a minimum above 20 °C |
| Jours de gel | days with a minimum below 0 °C |
| Amplitude thermique | hottest minus coldest monthly mean temperature |
| Précipitations | sum of the daily precipitation |
| Jours de pluie | days with at least 1 mm of precipitation |
| Ensoleillement | sum of the daily sunshine duration, in hours (ranked highest first) |
| Jours de neige | days with at least 1 cm of snowfall |
| Déficit hydrique estival | June to August sum of reference evapotranspiration (ET0) minus precipitation |
| Jours de vent fort | days with a maximum gust of at least 60 km/h |

Missing days are skipped. Lower is better for the heat, rain, water deficit and
wind criteria; the others are ranked highest first, or not compared at all
(temperature, frost, amplitude, precipitation, snow: a matter of taste).

## Caveats

- The grid is 9 to 25 km wide: a centroid value is the climate of the area, so
  the urban heat island is not captured (cities look slightly cooler than they
  feel, especially at night).
- Gusts are smoothed by the model and underestimate local extremes.
- Reanalysis sunshine duration is a model output and runs higher than station
  measurements: compare units with each other rather than with Météo-France
  figures.
- Reanalysis is not station data; relief (mountain valleys) is averaged out.

## Refreshing

```
bin/console app:import:criteria:climate --download   # complete the vendored CSV, then import
bin/console app:import:criteria:climate               # import only
```

The download is resumable: units already in `resources/geo/climat-normales.csv`
are skipped, and the file is written chunk by chunk. When a quota is hit the
command stops and says so; re-run it later. Delete the file to recompute
everything.
