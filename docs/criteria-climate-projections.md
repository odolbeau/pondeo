# Climate projections

Each urban unit (see `docs/urban-units.md`) carries three projected figures
towards 2050, under the "Climat 2050" group of its detail page (see
`docs/urban-unit-detail.md`), with the usual rank and median of all urban
units. They are stored with the reference year 2050.

## Source

The [Open-Meteo Climate API](https://open-meteo.com/en/docs/climate-api):
CMIP6 climate models (HighResMIP), downscaled to 10 km and bias-corrected
onto the ERA5-Land reanalysis. Free for non-commercial use, data under
CC BY 4.0, hence the credit "Données météo : Open-Meteo.com (CC BY 4.0)" on
the home page. Only daily temperatures are used.

## Criteria

All are ranked from the lowest: the 1st unit is the one that stays coolest.

| Criterion | Meaning |
|---|---|
| Jours > 35 °C vers 2050 | Mean number of days per year with a maximum above 35 °C |
| Nuits tropicales vers 2050 | Mean number of nights per year with a minimum above 20 °C |
| Hausse de température vers 2050 | Mean temperature of the projection window minus that of 1991-2020, in °C |

## Method

- **One point per unit**: the unit's centroid, snapped to a 0.1° grid (about
  10 km, the API's resolution). Units whose centroids fall in the same cell
  share its figures.
- **Windows**: the projection window is 2041-2050 and the reference period
  1991-2020. The API serves nothing after 2050-12-31, so the usual 2041-2060
  window is out of reach; ten years average out less of the year-to-year
  variability than twenty, which matters most for the rare hot days.
- **Same API for the baseline**: the reference period comes from the same
  models, not from observations. Models have their own biases; a difference
  between two figures of the same model cancels most of them, where a model
  minus an observed climate would not.
- **Model averaging**: everything is computed per model (yearly counts, then
  the mean of the years; the temperature difference), then averaged over the
  models. A model with no data for a year, or for the baseline, is left out.
  The models are listed in the downloader; one that stops at 2049 is not used.
- A unit without a centroid, or a cell for which no model has data, gets no
  value.

## Not a forecast

These figures are model output: the projection of a trend under one
emissions scenario, not a prediction of any given year. A single year can be
far above or below the mean, and the models disagree with each other,
especially on extremes such as days above 35 °C. Compare units with one
another rather than reading an absolute value.

## Refreshing

```
bin/console app:import:criteria:climate-projections --download   # recompute the vendored CSV, then import
bin/console app:import:criteria:climate-projections              # import only
```

The free API quota (10 000 calls a day, a call being weighted by the variables
and days requested) is metered per variable and day, and a full run at 0.1°
needs weeks of it. The download is therefore resumable: the
cells already computed are kept in `var/` and a re-run carries on from there,
stopping cleanly when the quota is hit. The CSV is only written, and imported,
once every cell is done.
