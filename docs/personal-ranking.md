# Personal ranking

A public page at `/mon-classement` that ranks the unités urbaines by what the
visitor cares about, instead of by one criterion at a time (see
`docs/rankings.md`).

## The URL is the state

Each criterion that has a ranking gets an importance from 0 to 5:
`/mon-classement?w[prix_m2]=3&w[taux_chomage]=5`. A criterion at 0, absent or
unknown is ignored; an importance outside 0-5 is rejected. With nothing above
0 the page only shows the sliders. The result can be paginated (`?page=`, 50
rows) and the address can be shared as is.

## Score

Each criterion is turned into a **percentile** among all unités urbaines: 1 for
the best, 0 for the worst. The best end is the one the criterion's ranking
uses (low unemployment, high living standard...), except for prices, where
cheap is what people want. The population and the density are left out: whether
big or small is better is a matter of taste, which one importance cannot say. So is the
density of associations, which says as much about a town's role in its area as about its
life. The score is the
average of a unit's percentiles weighted by the importance, scaled to 0-100.
Ranks are shared on ties, as in the single-criterion rankings.

Percentiles rather than raw values, so that one extreme city (Paris for the
population or the prices) does not flatten everybody else.

## Filters

The filters of the map narrow down the units that are ranked, without changing
the scores; see [filters.md](filters.md). The importances are kept in the
browser from one visit to the next, like the filters.

## Missing data

A unit with no value for a criterion is scored on the others. It is only ranked
if the criteria it has hold at least half of the total importance chosen:
otherwise a unit would win on a single flattering figure.

## Strengths

Under each unit, up to three criteria where it sits in the best quarter, those
weighing the most in its score first.

## Limits

A percentile says "better than X % of the units", not "by how much". Each
criterion is read at its own latest period, which can differ from one criterion
to another.
