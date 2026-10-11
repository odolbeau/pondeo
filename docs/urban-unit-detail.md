# Urban unit detail

Everything the app knows about one urban unit (see `docs/urban-units.md`) is
shown in two places that share the same content: a side panel on the map, and
a full page.

## Where it lives

- **Full page:** `/unites-urbaines/<code>`, where `<code>` is the unit's
  5-character INSEE code. Unknown code: 404.
- **Map panel:** clicking a zone on `/carte` highlights it and opens the same
  content in a panel next to the map, so several units can be compared
  without losing the map's filter and zoom. The panel offers a link to the
  full page.
- **Shareable selection:** the selected unit is kept in the map URL
  (`/carte?unite=<code>`, alongside the population filter), so a refresh or a
  shared link reopens it.

## Content

- **Header:** name, département and région, its category in the [aires d'attraction](urban-unit-attraction.md) (e.g. "Couronne · aire d'attraction de Vannes"), population with its rank.
- **Criteria**, one section per [group](criteria-import.md#groups), each
  listing its criteria with the latest value, its period and, for numeric
  criteria, a chart of the series (see [Criterion chart](#criterion-chart)). A search
  field filters criteria by label (accent- and case-insensitive).
- **Points of interest**, by category: the count, plus a sample of each.
- **Communes** making up the unit.

## Rank and median

For a numeric criterion (integer or decimal), the unit is compared with every
urban unit that has a value **for the same period** — the unit's own latest
period for that criterion:

- **Rank:** position by descending value (1 = highest), out of the number of
  units compared. Ties share the same rank. Whether high is "good" is not
  encoded: the rank only orders.
- **Median:** median of the compared values, as a reference point.

Text and boolean criteria have no rank or median.

## Criterion chart

A numeric criterion with at least two periods unfolds, on a click on its row, a
chart of its evolution (also in the map panel). Hovering shows the value of
each year.

- **Two curves:** the unit's values, and the **median of all urban units** for
  each year, the same reference as the "median" shown next to the latest value.
  A criterion that is not ranked has no median curve, only the unit's.
- **Base 100:** a button redraws both curves as an index, 100 being the first
  year where both have a value. It compares how the two evolve rather than their
  level, which is what matters for a count such as the population (a city of
  80 000 inhabitants against a median of a few thousand).
- **Loaded on demand:** the series is fetched the first time the row is
  opened. A unit with no value for the criterion, or a text or boolean
  criterion, has no chart.
