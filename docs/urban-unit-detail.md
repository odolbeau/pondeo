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
- **Facilities**: health, sport and culture, services and shops each have their section or group of criteria (see [equipements-bpe.md](equipements-bpe.md)).
- **Shops by type**, in a collapsed block of the "Cadre de vie" section: the count per year and the change (see [criteria-commerces.md](criteria-commerces.md#detail-by-type)).
- **Points of interest**, grouped by the root of their category's tree (a category without parent is its own group; the category is then shown next to each POI's name), in a section of their own at the bottom of the page: every POI of the unit is listed on the left, and plotted on a map on the right, one colour per category. Hovering a POI in the list highlights its point on the map, and the other way round; clicking one opens its name and address on the map. The outline of the unit is drawn on the map, which opens on the whole unit. A settings button opens the whole tree of categories, to keep or drop each one, a parent standing for all its children; a category that is not kept shows nowhere, neither in the list nor on the map, and a group left empty disappears. The choice is remembered in the browser and applies to every unit's page. A place filed more than once (a plant found in two sources, for instance) is listed once, with all its categories: within a group, two POIs are the same place when they are very close and their names agree. The POIs themselves are not merged, so they can still be counted per category. A POI's activity, when its source gives one, is shown next to its name. In the narrow map panel, only their count is shown, with a link to this section of the full page. Among them: [Seveso sites](poi-seveso-sites.md), [hospitals](poi-hospitals.md), [tram and metro stations](poi-transit-stops.md).
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
