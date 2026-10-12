# Board game associations

The board game clubs, ludothèques and other game associations are stored as
points of interest (POIs) of the category "Association de jeux de société"
(`board_game_association`). They answer one question: is there at least one in
a given urban unit, and how many?

## Source

[Subverti's directory of board game structures](https://subverti.com/fr/maps/associations/),
used **with Subverti's written permission**: the site's terms forbid extracting
its content, and the permission was obtained by email (October 2026). It is not
an open licence: keep the permission on file, credit Subverti and link to the
directory wherever the data is shown.

- **Scope:** the structures located in France (~860). Each one has a type
  (club, ludothèque, national network, other association), kept in its
  `attributes` with the link to its page in the directory.
- **Curated:** the directory is maintained by hand and by submissions, so it
  has far fewer false positives than a filter on the national associations
  register, but it is not a census: a club nobody submitted is missing.
- **Location:** the position given by the directory (WGS84), stored as a
  longitude/latitude point like every other POI.
- **Identity:** a structure is matched on its slug in the directory, so a
  refresh updates it in place.

The file is vendored under `resources/geo/` (Git LFS), like the other
reference data.

## Unité urbaine

Each structure is attached to the urban unit whose boundary contains it (see
`docs/urban-units.md`). One outside every unit is kept, attached to none.

## Map filter

"Exclure les villes sans association de jeux" keeps only the urban units that
contain at least one board game structure. It is off by default, and combines
with the other filters.

## Detail page

The urban unit page lists the count and a sample of structures, like any other
category.

## Refreshing

```
bin/console app:import:pois:board-game-associations --download
git diff resources/geo/
git add resources/geo/ && git commit
```

`--download` refreshes the vendored file from the directory (one page), then
imports it. The import is an idempotent upsert that removes the structures the
source no longer lists. Import the zones first (`app:import:zones`);
`make db-reset` does both in the right order.
