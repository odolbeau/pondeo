# Real estate prices

Each urban unit (see `docs/urban-units.md`) carries its average price per m²
for 2021 to 2025, under the "Achat" group of its detail page (see
`docs/urban-unit-detail.md`). Like every criterion, it gets a rank (1 = the
most expensive), the median of all urban units and a small curve of its
evolution.

## Source

The "Statistiques DVF" dataset on data.gouv.fr (Etalab, from the DGFiP's
Demandes de valeurs foncières, open licence), monthly file at commune level.
DVF lists every notarised sale in France.

The source already keeps only sales of a single property, without outbuildings,
with a computable price per m². Its history starts in 2021 and the vendored
file keeps one row per commune, year and property type.

## Criteria

| Criterion | Property types |
|---|---|
| Price per m² | apartments and houses together |
| Price per m² (apartments) | apartments |
| Price per m² (houses) | houses |

Shops and other premises are left out.

- **Aggregation:** a price is not additive. A commune's yearly price is the
  average of its monthly averages weighted by their number of sales, and an
  urban unit's price is the average of its communes' prices weighted by their
  number of sales. Communes outside every urban unit are skipped.
- **Average, not median:** the source gives monthly medians that cannot be
  combined into a yearly one, so the average is used. It is more sensitive to
  a few exceptional sales than a median would be. The "median" shown next to a
  price is the median of the urban units' prices, as for every criterion.

## Known gaps

- Bas-Rhin, Haut-Rhin, Moselle and Mayotte are not in DVF (a different land
  registry system): their urban units have no price.
- DVF reports Paris, Lyon and Marseille per arrondissement; they are folded back
  into their commune.
- A small commune may have only a few sales in a year; the weighting in the
  urban unit limits the noise but does not remove it.

## Map filter

The map (`/carte`) can cap the price per m² (latest year), for apartments and
houses together (the default), houses only, or apartments only. Under the field,
the national median and quartiles of the chosen type help pick a cap; each can
be applied with a click. It is an upper bound only; leaving it empty disables the filter.
A unit without a price never matches a cap. The value is kept in the map URL
like the other filters.

## Refreshing

```
bin/console app:import:criteria:real-estate-prices --download
git diff resources/geo/
git add resources/geo/ && git commit
```

Without `--download`, the vendored file is imported as is. The download is one
file of about 270 MB. The import is an idempotent upsert. Import the zones first
(`make db-reset` runs everything in the right order).
