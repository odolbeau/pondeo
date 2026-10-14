# Facilities (BPE)

The project vendors every facility count INSEE publishes in a comparable way
over time, not only the shops, so that a new criterion on health, sport,
services or culture starts from data already in the repository. Only the shops
(see `docs/criteria-commerces.md`) are imported into criteria so far.

## Source

INSEE's Base permanente des équipements (BPE), "BPE en évolution" series,
through the [Mélodi API](https://portail-api.insee.fr/) (dataset
`DS_BPE_EVOLUTION`, no authentication, open licence). It gives the number of
facilities on 1 January of **2015, 2020 and 2025**, per urban unit and facility
type, for the 150 types INSEE keeps comparable over time.

## What is in it

| Domain | Types kept |
|---|---|
| A Services for individuals | 30 of 45: banks, post offices, town halls, gendarmeries, driving schools, building trades, hairdressers, vets, estate agents, restaurants… (police and part of the courts are left out) |
| B Shops | 34 of 35 (EV charging stations are left out) |
| C Education | 3 of 25: only the agricultural establishments. Schools, colleges, lycées and universities are not in the series |
| D Health and social action | 56 of 77: hospitals, emergency units, maternity wards, pharmacies, 27 medical specialities, nurseries, care for the elderly and the disabled |
| E Transport | 2 of 5: taxis and airports |
| F Sport, leisure, culture | 21 of 37: 18 sports facilities, cinemas, conservatories, libraries |
| G Tourism | 4 of 5: travel agencies, hotels, campsites, tourist offices |

The list of kept types, with their INSEE label (upper case, as published),
domain and sub-domain, is vendored next to the data. Two aggregate codes of the
series (A10G, E10G) are not documented by INSEE and are left out.

A type counts **establishments**, not capacity or staff. A rise can come from
how facilities are declared rather than from more of them: INSEE warns about
it for the liberal medical professions, whose count follows the number of
practice addresses.

## Files

| File | Content |
|---|---|
| `equipements-bpe.csv` | Counts: unit, year, type, count. A unit with no row for a type and year has none (or the figure is under statistical secrecy) |
| `equipements-bpe-types.csv` | The types of the series: code, domain, sub-domain, label |

Only the urban units of the project are kept. Whatever INSEE adds to the series
later is not downloaded until the types file lists it.

## Refreshing

The shop import's `--download` option refreshes the whole file (about ten
minutes, requests are throttled):

```
bin/console app:import:criteria:commerces --download
git diff resources/geo/
git add resources/geo/ && git commit
```

In dev, run the command with `--no-debug`: the profiler would otherwise keep
the responses of the several hundred requests in memory.

The series holds three years, five years apart: the next vintage may move them
on.
