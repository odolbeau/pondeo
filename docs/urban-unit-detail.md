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

See also [comparison.md](comparison.md) to put several units side by side.

## Content

- **Header:** name, département and région, its category in the [aires d'attraction](urban-unit-attraction.md) (e.g. "Couronne · aire d'attraction de Vannes"), population with its rank.
- **Criteria**, one section per [group](criteria-import.md#groups), each
  listing its criteria with the latest value, its period and, for numeric
  criteria, a chart of the series (see [Criterion chart](#criterion-chart)). A search
  field filters criteria by label (accent- and case-insensitive).
  The "Âge" group (share of each age bracket, see [criteria-age-structure.md](criteria-age-structure.md))
  is left out of the narrow map panel, where it would only repeat the pyramid:
  the full page has it.
  A group whose criteria are the brackets of one distribution draws them as a
  single chart against the median of all urban units, instead of one row per
  bracket: columns for the tax households by bracket of income (see
  [criteria-tax-income.md](criteria-tax-income.md)), rows for the
  socio-professional categories, whose labels are longer (see
  [criteria-pcs.md](criteria-pcs.md)).
- **Population pyramid**, by sex and five-year age bracket (see [population-pyramid.md](population-pyramid.md)).
- **Shops by type**, in a collapsed block of the "Cadre de vie" section: the count per year and the change (see [criteria-commerces.md](criteria-commerces.md#detail-by-type)).
- **Points of interest**, by category: the count, plus a sample of each.
- **Communes** making up the unit.

## Chart tooltips

The charts drawn as SVG (the pyramid, the income brackets, the
socio-professional categories) show their figures in a tooltip that follows the
pointer and appears at once, over the whole column, row or age bracket and not
only over its bar, rather than in the browser's own tooltip, which only shows
after a delay. Each chart says under it that it can be hovered; a tap shows the
tooltip on a touch screen. The pyramid also has its numbers in a table.

## Rank and median

For a numeric criterion (integer or decimal), the unit is compared with every
urban unit that has a value **for the same period** — the unit's own latest
period for that criterion:

- **Rank:** position by descending value (1 = highest), out of the number of
  units compared. Ties share the same rank. Whether high is "good" is not
  encoded: the rank only orders, so 1st is the highest value, not the best.
  A criterion where a high value is a handicap can be ranked from the lowest
  value instead (1 = lowest): this is the case of the poverty rate, so that
  its 1st is the unit with the least poverty.
- **Median:** median of the compared values, as a reference point.

Text and boolean criteria have no rank or median.

## Trend

A numeric criterion with at least two periods shows, under its latest value,
how it moved since the first period of its series: a relative change in %, or
a difference in points for a criterion expressed in %. A change that is
negligible (under 0.5 %, or 0.1 point) is shown as stable.

The arrow is colored from the criterion's rank order: where a high value is the
better one (the default) a rise is green and a fall red, and the reverse for a
criterion ranked from the lowest value. So the color says whether the move is
favourable under the same convention as the rank, not whether it is good for
everyone: a rise in prices shows green because the ranking starts from the most
expensive.

A criterion that is already a rate of change (the average yearly population
growth) has no trend.

The same badge appears in the [rankings](rankings.md#trend) and in the
[comparison](comparison.md#trend). Hovering it shows the period it covers.

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
