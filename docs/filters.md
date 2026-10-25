# Filters

The same filters narrow down the unités urbaines on the map (`/carte`), on every
ranking page (`/classements/{code}`) and on the personal ranking
(`/mon-classement`). They are one panel, with the same parameters in the
address of each page, so a selection made on one page applies to the others.

## What can be filtered

| Filter | Keeps |
|---|---|
| Population | the units between two populations |
| Attraction areas | without the "couronnes" of another city, without the secondary poles |
| Train | the units from which a destination city (Paris, Lyon, ...) is within a number of minutes, car to the station included, optionally with a maximum of transfers (see [station-journeys.md](station-journeys.md)) |
| Stations | the units with a railway station |
| Shops, restaurants | at least a density per 1000 inhabitants |
| Price | at most a price per m², for apartments, houses or both (see [criteria-real-estate.md](criteria-real-estate.md)) |
| Nuclear plants | the units farther than a distance from any plant |
| Board games | the units with an association |

A filter that is not set keeps everything. A unit without a value for a
criterion cannot be said to reach a threshold on it, so it is left out (the
units of Alsace-Moselle have no price, for instance).

## The address is the state

The filters are query parameters (`?populationMin=20000&trainMaxMinutes[paris]=120`),
the same on every page, so a link shows what its author saw. A parameter at its
default is left out.

## On the rankings

A filtered ranking ranks the selection among itself: the first unit kept is
ranked 1st, and the page says how many units are kept out of how many. The
median shown as a reference stays the national one, so that it keeps its
meaning whatever the filter.

On the personal ranking, the score of a unit is computed against every unité
urbaine, then the units kept are ranked among themselves: a score means the same
with or without a filter, and so do the "strengths" listed under each unit.

The ranking index (`/classements`, a top 3 per ranking) is not filtered.

## Always visible, never hidden

A page that is filtered says so: the number of active filters is highlighted
next to a "Réinitialiser" link that clears them all. On the rankings, the panel
sits on the left of the results on a large screen, as on the map, and opens from
a "Filtres" button on a small one; the results refresh in place when a filter
changes. The personal ranking shows the filters and the importances of the
criteria side by side, and the results beside them; on a smaller screen, the two
are stacked.

## The last filter is kept

A visitor's filters do not change much, so they are kept from one page to the
next: the last ones used are saved in the browser (a cookie, which only holds
the filter), and a page opened without any filter in its address is sent to the
same page with them. The importances of the personal ranking are kept the same
way.

- An address that holds filters wins over the saved ones: a shared link shows its
  filters, and does not replace the saved ones.
- Filters are saved when the visitor changes them, not when a page is opened.
- Resetting the filters forgets them.
- The cookie is only a preference (no tracking, so no consent banner), and a
  saved filter that is not valid is ignored.
- Search engines have no cookie: they always see the pages without filters, and
  the canonical address of a page never carries them.
