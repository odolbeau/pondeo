# Board game associations

The board game clubs, ludothèques and other game associations are stored as
points of interest (POIs), one category per type, all under the parent category
"Association de jeux de société" (`board_game_association`). They answer one
question: is there at least one in a given urban unit, and how many? Their category shares an ancestor, "Jeux de société" (`board_game`), with the shops and bars, which have their own page: `docs/poi-board-game-stores.md`.

## Source

An export of [Subverti's directory of board game structures](https://subverti.com/fr/maps/associations/),
provided by its publisher under the
[CC BY-SA 4.0](https://creativecommons.org/licenses/by-sa/4.0/deed.fr) licence.

- **Attribution:** credit the publisher and link to the directory (done on the
  sources page of the site).
- **Share-alike:** the vendored files are an adaptation of the export, so they
  stay under CC BY-SA 4.0 (the code of the project keeps its own licence).
- **Curated:** the directory is maintained by hand and by submissions: it is
  not a census, a club nobody submitted is missing.

## Types

| Type in the file | Category | Label |
|---|---|---|
| `club` | `board_game_club` | Club de jeux |
| `ludo` | `board_game_library` | Ludothèque |
| `rese` | `board_game_network` | Réseau national de jeux |
| `othe` | `board_game_other_association` | Autre association ludique |

## Vendored file

`resources/geo/jeux-associations.csv` (Git LFS, like the other reference
data) is a **reduced** copy of the export: only the structures located in
France that are not defunct, and only these columns:

`id` (the directory's slug), `name`, `type`, `city`, `lat`, `lon`, `website`.

Contact details (emails, phone numbers), descriptions and social links of the
export are deliberately not kept, and the street address neither: a small
club's head office is often a private home.

- **Location:** the position given by the directory, WGS84.
- **Identity:** an association is matched on its `id`, so a refresh updates it
  in place, and moves it when its type changes.
- **Unusable rows:** a structure without a usable position is left out.

## Unité urbaine

Each association is attached to the urban unit whose boundary contains it (see
`docs/urban-units.md`). One outside every unit is kept, attached to none.

## Map filter

"Exclure les villes sans association de jeux" keeps only the urban units that
contain at least one association, of any type. It is off by default, and
combines with the other filters. Shops and bars do not count.

## Detail page

The urban unit page lists, for each type, the count and a sample of
associations, like any other category.

## Refreshing

There is no automated refresh. Ask the publisher for a new export, rewrite the
file keeping the columns above, then:

```
bin/console app:import:pois:board-games
git diff resources/geo/
git add resources/geo/ && git commit
```

The import is an idempotent upsert that removes the associations the file no
longer lists. Import the zones first (`app:import:zones`); `make db-reset` does
both in the right order.
