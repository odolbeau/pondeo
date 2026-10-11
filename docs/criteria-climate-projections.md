# Climate projections

Each urban unit (see `docs/urban-units.md`) carries six projected figures
towards 2050, under the "Climat 2050" group of its detail page (see
`docs/urban-unit-detail.md`), with the usual rank and median of all urban
units. They are stored with the reference year 2050.

## Source

The [Open-Meteo Climate API](https://open-meteo.com/en/docs/climate-api):
CMIP6 climate models (HighResMIP), downscaled to 10 km and bias-corrected
onto the ERA5-Land reanalysis. Data under CC BY 4.0, hence the credit
"Données météo : Open-Meteo.com (CC BY 4.0)" on the home page. Daily
temperatures (maximum, minimum, mean) and precipitation are used.

## Criteria

| Criterion | Meaning | Ranked from |
|---|---|---|
| Jours > 35 °C vers 2050 | Mean number of days per year with a maximum above 35 °C | lowest |
| Nuits tropicales vers 2050 | Mean number of nights per year with a minimum above 20 °C | lowest |
| Hausse de température vers 2050 | Mean temperature of the projection window minus that of 1991-2020, in °C | lowest |
| Vagues de chaleur vers 2050 | Mean number of heat waves per year: runs of at least 3 consecutive days with a maximum above 30 °C (a long run counts once) | lowest |
| Évolution des précipitations vers 2050 | Change of the yearly precipitation between 1991-2020 and the projection window, in % | not ranked |
| Incertitude sur la hausse de température | Spread between the models of the temperature rise (highest minus lowest), in °C | lowest |

More or less rain is not plainly better or worse, so the precipitation change
is shown but not ranked.

The uncertainty measures how much the models disagree on the rise, not how
risky the rise is: a unit can be certain of a large rise.

## Method

- **One point per unit**: the unit's centroid, snapped to a 0.1° grid (about
  10 km, the API's resolution). Units whose centroids fall in the same cell
  share its figures.
- **Windows**: the projection window is **2041-2050** and the reference period
  1991-2020. The API serves nothing after 2050-12-31, so the usual 2041-2060
  window is out of reach; ten years average out less of the year-to-year
  variability than twenty, which matters most for the rare hot days.
- **Same API for the baseline**: the reference period comes from the same
  models, not from observations. Models have their own biases; a difference
  between two figures of the same model cancels most of them, where a model
  minus an observed climate would not.
- **Model averaging**: everything is computed per model (yearly counts, then
  the mean of the years; the differences), then averaged over the models. A
  model with no data for a year, or for the baseline, is left out. The
  uncertainty is the one figure that is not averaged: it is the spread of the
  per-model temperature rises, and needs at least two models. The models are
  listed in the downloader; one that stops at 2049 is not used.
- A unit without a centroid, or a cell for which no model has data, gets no
  value.

## Not a forecast

These figures are model output: the projection of a trend under one
emissions scenario, not a prediction of any given year. A single year can be
far above or below the mean, and the models disagree with each other,
especially on extremes such as days above 35 °C. Compare units with one
another rather than reading an absolute value.

## Raw archive

Calling the API is the costly part, so every answer is kept as received, before
any derivation: one compressed file per grid cell, period, list of models and
list of variables, holding the daily series **of each model** (models are never
averaged before archiving). It lives outside the repository, never committed,
in the `projections/` subdirectory of the root given by `OPENMETEO_ARCHIVE_DIR`
(by default `~/pondeo-data/open-meteo/`).

- A cell whose files exist is never requested again, which makes the download
  resumable after any interruption.
- The vendored CSV is derived from the archive alone: once every cell is
  archived, rebuilding it (for instance after changing a threshold) needs no
  network and no key.
- The archive also records the estimated cost of each request, to keep track
  of the quota across runs.

## Refreshing

```
bin/console app:import:criteria:climate-projections --download   # archive what is missing, derive the CSV, import
bin/console app:import:criteria:climate-projections              # import only
```

With `OPENMETEO_API_KEY` set (in `.env.local`), the paid API is used; without,
the free one. The free quota (10 000 calls a day, a call being weighted by the
variables and days requested) is metered per variable and day, and a full run at
0.1° needs weeks of it: the paid plan is what makes the run practical. The
download stops when the quota is hit, when the key is refused, or when the cost
projected for the whole run exceeds the call budget set in the downloader; the
CSV is only written, and imported, once every cell is archived.
