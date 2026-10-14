# Facilities (BPE)

The project vendors every facility count INSEE publishes in a comparable way
over time, not only the shops, so that a new criterion starts from data
already in the repository. Facilities are grouped into families (the shops are
described in `docs/criteria-commerces.md`), each of which is a criterion of
the comparison.

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

## Criteria

Each family sums the facility types listed for it, and is stored as a count
and a density per 1000 inhabitants (computed from the stored count and
population, never summed), like the shops. The detail page shows the density,
with the count in its title, its rank, the median of all urban units and the
evolution over the years. The counts have no rank of their own: they follow
the size of the town.

| Group (section of the detail page) | Family | Types counted |
|---|---|---|
| Santé (Santé, sport et culture) | General practitioners | general practitioners |
| | Specialists | the medical specialities, without the generalists, dentists, midwives and physiotherapists |
| | Dentists | dental surgeons |
| | Physiotherapists, podiatrists, midwives | those three professions |
| | Pharmacies | pharmacies |
| | Care establishments | hospitals and clinics of every kind (short stay, rehabilitation, long stay, psychiatric); emergency units and maternity wards sit inside them and are not counted again |
| Petite enfance et seniors (Santé, sport et culture) | Nurseries | establishments for young children |
| | Homes for the elderly | accommodation for the elderly |
| Sport et culture (Santé, sport et culture) | Sports facilities | 18 types of sports facilities (pools, tennis courts, gyms, pitches, fitness rooms…) |
| | Cultural facilities | cinemas, libraries, conservatories |
| Services et tourisme (Cadre de vie) | Restaurants | restaurants and fast food, a single type in the BPE |
| | Everyday services | banks, post offices, hairdressers, beauty salons, dry cleaners |
| | Tourist accommodation | hotels and campsites |

Left out on purpose: the public services (courts, town halls, police), the
taxis and VTC, the building trades, and the types too rare for a comparison
to mean anything (most specialities taken one by one, ice rinks…). The types
of a family are listed in the code with the other definitions of the criteria.
Education is not in the series (see above).

## Files

| File | Content |
|---|---|
| `equipements-bpe.csv` | Counts: unit, year, type, count. A unit with no row for a type and year has none (or the figure is under statistical secrecy) |
| `equipements-bpe-types.csv` | The types of the series: code, domain, sub-domain, label |

Only the urban units of the project are kept. Whatever INSEE adds to the series
later is not downloaded until the types file lists it.

## Refreshing

The facility import's `--download` option refreshes the whole file (about ten
minutes, requests are throttled):

```
bin/console app:import:criteria:equipements --download
git diff resources/geo/
git add resources/geo/ && git commit
```

In dev, run the command with `--no-debug`: the profiler would otherwise keep
the responses of the several hundred requests in memory.

The series holds three years, five years apart: the next vintage may move them
on.
