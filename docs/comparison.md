# Comparison

Several urban units (see `docs/urban-units.md`) can be put side by side on one
page, criterion by criterion.

## Where it lives

- **Page:** `/comparer?villes=<code>,<code>`, where each `<code>` is a unit's
  5-character INSEE code. The URL is the whole state, so it can be shared.
- **Entry points:** the "Comparer" link in the navigation, and a link on each
  unit's detail page that starts a comparison with that unit.
- **Limits:** 2 to 4 units. With fewer than 2 the page only asks for more;
  an unknown code is a 404; more than 4 codes is rejected.

## Content

- One column per unit, rows grouped like the [detail page](urban-unit-detail.md)
  (same groups, same order, same latest value per criterion).
- Units are added with a search field and removed with a chip.
- For numeric criteria that have a ranking, the best value of the row is
  highlighted. "Best" follows the criterion's direction (lower is better for
  some, see [rankings.md](rankings.md)), and nothing is highlighted when all
  units are tied or fewer than two have a value.
- A unit with no value for a criterion shows a dash.
- Distribution charts (income brackets, socio-professional categories) are
  not part of the comparison.

## Trend

Each cell shows the unit's trend next to its value, as on the
[detail page](urban-unit-detail.md#trend). A unit with a single period for a
criterion has none.
