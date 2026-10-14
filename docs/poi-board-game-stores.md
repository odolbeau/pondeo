# Board game shops and bars

The shops, bars and lodges specialised in board games are stored as points of
interest (POIs), one category per type, all under the parent category
"Boutique ou bar à jeux" (`board_game_store`). They show on the detail page of
the urban unit that contains them. No map filter uses them yet.

## Source

The same export as the associations (see `docs/poi-board-game-associations.md`):
[Subverti's directory](https://subverti.com/fr/maps/associations/), provided by
its publisher under [CC BY-SA 4.0](https://creativecommons.org/licenses/by-sa/4.0/deed.fr).
The attribution and share-alike terms are the same.

## Types

| Type in the file | Category | Label |
|---|---|---|
| `shop` | `board_game_shop` | Boutique de jeux |
| `cafe` | `board_game_cafe` | Bar à jeux |
| `shop-cafe` | `board_game_shop_cafe` | Boutique et bar à jeux |
| `gite` | `board_game_lodge` | Gîte ludique |

The export writes a shop that is also a bar as `shop,cafe`; the vendored file
uses `shop-cafe`.

## Vendored file

`resources/geo/jeux-boutiques.csv` (Git LFS) is a **reduced** copy of the
export: only the places located in France that are not defunct, and only these
columns:

`id` (the directory's slug), `name`, `type`, `address`, `lat`, `lon`, `website`.

Unlike an association's head office, a shop's address is its premises, so it is
kept (street, postal code, city). Contact details and opening hours are not.

- **Location:** the position given by the directory, WGS84. A few rows write
  it with decimal commas or a trailing zoom level; they are normalised.
- **Identity, unusable rows, unité urbaine, refreshing:** as for the
  associations. Both files are imported by the same command.
