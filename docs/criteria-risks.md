# Natural and technological risks

The "Risques" group of the urban unit page. All indicators are **lower is
better**: a unit is ranked from the least exposed.

```
bin/console app:import:criteria:risks --download   # refresh the vendored CSV, then import
bin/console app:import:criteria:risks               # import only
```

## Source

The [Géorisques API](https://georisques.gouv.fr/api/v1) (Ministry of Ecological
Transition and BRGM; open, no key), queried per commune for the communes of the
urban units. The result is vendored as `resources/geo/risques-communes.csv`
(`code_insee,year,measure,value,population`, one row per commune and measure;
`year` is the year of the download).

The download makes about five calls per commune, throttled and retried when the
API throttles or fails: expect one to two hours. It is **resumable**: communes
already written for the current year are skipped, so an interrupted run is
simply started again. Rows left over from an earlier year are dropped. A commune
for which the API has no answer on a measure gets no row for it, never a
made-up zero.

## Indicators

| Criterion | Per commune | Per urban unit |
|---|---|---|
| Arrêtés CatNat sur 20 ans (par commune) | number of natural-disaster decrees published in the Journal officiel over the last 20 years | average per commune, weighted by population |
| Population en zone inondable | 100 if flooding is a listed risk of the commune, else 0 | % of the unit's population |
| Exposition au retrait-gonflement des argiles | exposure class, 1 (low) to 3 (high), at a point inside the commune | average class, weighted by population |
| Population exposée aux feux de forêt | 100 if forest fire is a listed risk of the commune, else 0 | % of the unit's population |
| Zone sismique | regulatory seismic zone, 1 (very low) to 5 (strong) | highest zone among the communes |
| Population en zone à fort potentiel radon | 100 if the commune is in radon potential class 3, else 0 | % of the unit's population |

Weighting by population turns a commune flag into a share of inhabitants: a
unit whose only flooded commune holds a quarter of its population reads 25 %.
The seismic zone is not an average: one commune in a strong zone makes the unit
one, as the zone is regulatory and drives building rules.

## What these figures are not

They describe **recorded or regulatory hazards, not probabilities of a
disaster**:

- the CatNat count is the observed history of the state's disaster
  recognitions (a claims history), which also reflects how often a commune
  applied for recognition;
- "zone inondable" and "feux de forêt" say that the risk is listed for the
  commune in the national risk inventory (GASPAR), not how large or how
  likely it is, nor that every inhabitant is exposed: the share is a share of
  the population of the *communes* concerned;
- the seismic zoning and the radon classes are regulatory classifications.

## Clay shrink-swell exposure

No per-commune file is published for the BRGM exposure map; the Géorisques
`rga` endpoint only answers for a point. The class is therefore sampled at one
point inside each commune (a point guaranteed to lie within its boundary) and
averaged over the unit. It is an approximation: a large commune whose clay
varies is represented by a single spot, and a point outside the mapped area
yields no value, so the commune does not enter the average.

## Coverage

Every urban unit has a CatNat count and a seismic zone. A few communes are not
in the national risk inventory (so flooding and forest fire have no value for
units made only of them), and the clay exposure map leaves some areas
unmapped: for those units the criterion is simply absent.
