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

- **Header:** name, département and région, population with its rank.
- **Criteria**, one section per [group](criteria-import.md#groups), each
  listing its criteria with the latest value, its period and, for numeric
  criteria, a sparkline of the series over the available periods. A search
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
