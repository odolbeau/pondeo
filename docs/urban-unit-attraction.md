# Urban units and aires d'attraction

An urban unit (see `docs/urban-units.md`) is a morphological notion: a
continuous built-up area. It says nothing about whether the town is
independent or a satellite of a bigger one. Arradon is its own urban unit, yet
most of its workers commute to Vannes. INSEE's **aires d'attraction des villes
2020** (AAV, which replaced the *aires urbaines*) capture exactly that, from
commuting flows: each commune is a pôle or part of a **couronne** (at least
15 % of its workers go to the pôle).

The app stores that zoning on the urban units so the map can leave out the
satellites.

## Category of an urban unit

INSEE gives each commune a category:

| Code | Meaning                              |
|------|--------------------------------------|
| 11   | Commune-centre of a main pôle        |
| 12   | Other commune of a main pôle         |
| 13   | Commune of a secondary pôle          |
| 20   | Commune of the couronne              |
| 30   | Outside any aire d'attraction        |

An urban unit takes the category of its **most populous commune** (its
ville-centre), and the aire it belongs to. Judging the unit on its other
communes would be wrong: Vannes's unit includes Saint-Avé, which is a couronne
commune, yet the unit as a whole is the pôle.

A unit whose ville-centre the zoning does not list (a recent merger, a
vintage mismatch) has no category and is never treated as a satellite.

## Filters

On the map, two independent switches leave out:

- the units whose ville-centre is in the **couronne** (category 20): the
  satellites such as Arradon;
- the units that are **secondary pôles** (category 13).

Neither is on by default: every unit is shown until asked otherwise.

## Source

INSEE, [base des aires d'attraction des villes 2020](https://www.insee.fr/fr/information/4803954),
communes composition, vendored as `resources/geo/communes-aires-attraction.csv`
(Git LFS, restricted to the communes of an urban unit). It is refreshed by
`app:zones:download` together with the other zone files, and applied by
`app:zones:import`.

The zoning is built from the 2016 census commuting data, with a yearly
geography update: the file's vintage (1 January of the year) is hardcoded in
the download command and bumped by hand.
