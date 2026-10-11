# Climate criteria

Twenty criteria in the "Climat" group, describing the typical climate of each
urban unit: the 1991-2020 normals. Same shape as the other criteria (see
`criteria-import.md`): one value per urban unit, stored with `period = 2020`
(the end of the normals period).

## Source

[Open-Meteo](https://open-meteo.com/) historical weather API (ERA5 reanalysis
family), licensed CC BY 4.0. The attribution "Données météo : Open-Meteo.com
(CC BY 4.0)" is displayed on the home page and under the "Climat" group of the
urban unit page; keep it wherever the figures are shown.

The figures are computed once and vendored, never fetched at runtime. A
30-year daily request is heavy: it counts for about 1 000 API calls per
location (calls grow with the number of variables and of days). The free API
(600 calls per minute, 5 000 per hour, 10 000 per day) would need months; the
download therefore uses the commercial plan, through an API key
(`OPENMETEO_API_KEY` in `.env.local`, never committed), and refuses to spend
more than a call budget (`--max-calls`) since the quota is shared by every
Open-Meteo use of the project.

## Location

One point per urban unit: its centroid. Centroids falling in the same 0.1°
cell (the resolution of the ERA5-Land grid) share a single location, which
removes a fifth of the requests. Several locations are sent per request.

## Raw archive

Every API response is kept before anything is derived from it, so that a new
criterion or a changed threshold never costs another call.

- One gzip file per cell, holding the response as received, outside the
  repository, in the `normales/` subdirectory of the root given by
  `OPENMETEO_ARCHIVE_DIR` (by default `~/pondeo-data/open-meteo/`). The file name carries the cell, the
  period and a fingerprint of the variable list: asking for other variables
  fetches new files instead of reusing stale ones. It is never committed.
- A ledger next to it records the calls spent by each request (an estimate:
  the API reports no counter). The download adds it up to enforce the budget.
- `app:import:criteria:climate --download` only calls the API for the cells
  missing from the archive, then rebuilds `resources/geo/climat-normales.csv`
  from the archive alone. An interrupted run resumes where it stopped, and
  re-running with a full archive makes no call.

## Derivations

Each measure is derived per calendar year from the daily series, then averaged
over the 30 years (except the trend, which is a regression over the years).

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
| Vagues de chaleur | episodes of at least 3 consecutive days with a maximum above 30 °C |
| Plus longue série sèche | longest run of consecutive days under 1 mm of precipitation |
| Jours de pluie forte | days with at least 20 mm of precipitation |
| Tendance de la température | not averaged: slope of the annual mean temperature over 1991-2020, in °C per decade |
| Jours de chaleur humide | days whose maximum wet-bulb temperature exceeds 22 °C |
| Température ressentie maximale | highest daily maximum apparent temperature of the year |
| Air sec en été | mean of the daily maximum vapour pressure deficit, June to August, in kPa |
| Humidité du sol en été | mean June to August soil moisture of the 0-7 cm and 7-28 cm layers, as a percentage by volume (ranked highest first) |

Runs and episodes do not span two years. A missing day is skipped, and ends a
run.

Wet-bulb threshold: over the first 255 grid cells (about 13 % of the units, in
INSEE code order, overseas included), the mean number of days per year above
20, 22, 24 and 25 °C is respectively 17, 3.5, 0.3 and 0.1 at the median. 24 °C
and above leaves a count of zero for a large share of units, 20 °C is reached
on a tenth of the year almost everywhere; 22 °C gives a count that
discriminates across the whole range.

Lower is better for the heat, rain, dry-spell, water-deficit, wind, dry-air
and warming criteria; soil moisture and sunshine are ranked highest first;
temperature, frost, amplitude, precipitation, snow and the trend are not
compared (a matter of taste). Soil moisture is stored as a percentage rather
than in m³/m³ because values are kept to two decimals.

## Caveats

- The grid is 9 to 25 km wide: a centroid value is the climate of the area, so
  the urban heat island is not captured (cities look slightly cooler than they
  feel, especially at night).
- Gusts are smoothed by the model and underestimate local extremes.
- Reanalysis sunshine duration is a model output and runs higher than station
  measurements: compare units with each other rather than with Météo-France
  figures.
- Reanalysis is not station data; relief (mountain valleys) is averaged out.
- Apparent temperature, wet-bulb temperature and vapour pressure deficit are
  computed by the model from its own temperature, humidity and wind: their
  extremes are as smoothed as the rest.
- Reanalysis soil moisture is a model state, not a measurement.

## Refreshing

```
bin/console app:import:criteria:climate --download   # fetch the missing cells, rebuild the CSV, import
bin/console app:import:criteria:climate               # import only
```
