# Rents

Each urban unit (see `docs/urban-units.md`) carries its advertised rent per m²
for apartments (all sizes, T1-T2, T3 and more) and houses, under the
"Immobilier" group of its detail page (see `docs/urban-unit-detail.md`), next
to the sale prices (see `docs/criteria-real-estate.md`). Like every criterion,
it gets a rank (1 = the most expensive) and the median of all urban units.

## Source

The ANIL "Carte des loyers" (indicateurs de loyers d'annonce par commune), on
data.gouv.fr, open licence 2.0, to be credited as "Estimations ANIL, à partir
des données du Groupe SeLoger et de leboncoin".

For each commune and property type, it gives a monthly rent per m², charges
included, predicted by an econometric model for a reference unfurnished
dwelling let in the 3rd quarter of the edition's year (52 m² for an apartment,
37 m² for a T1-T2, 72 m² for a T3 and more, 92 m² for a house). The model is
fitted on the ads of leboncoin and SeLoger.

A commune with enough ads gets its own estimate. Otherwise it gets the
estimate of its "maille", a group of comparable neighbouring communes (or, for
a few houses, of its EPCI). The vendored file keeps that level and the number
of ads in the commune.

## Criteria

| Criterion | Property types |
|---|---|
| Rent per m² (apartments) | apartments, all sizes |
| Rent per m² (T1-T2 apartments) | apartments of 1 or 2 rooms |
| Rent per m² (T3+ apartments) | apartments of 3 rooms or more |
| Rent per m² (houses) | houses |

- **Aggregation:** a rent is not additive. An urban unit's rent is the average
  of its communes' rents, weighted by their number of main residences rented
  unfurnished in the private sector, from the latest census year of the
  housing data (see `docs/criteria-housing.md`). That is the stock the ads
  describe, so the figure reads as the rent of a typical private rental of the
  unit. Social housing and furnished rentals are left out of the weight.
  Communes outside every urban unit, or without such rentals, weigh nothing.
- **Paris, Lyon and Marseille:** the source reports them per arrondissement.
  They are folded back into their commune, each arrondissement weighted by its
  number of ads, the only figure the source gives for it.

## Known gaps

- **One edition only.** The ANIL redraws its groups of communes and refits its
  model every year and states that editions cannot be compared, so only the
  latest is kept: there is no evolution over time.
- **Advertised rents.** They are the rents asked to new tenants, usually higher
  than what sitting tenants pay.
- **Shared estimates.** In a small urban unit, every commune may carry the
  estimate of its group: the unit's rent then describes the area more than the
  unit itself. Houses are more often in that case, being few on the rental
  market.

## Refreshing

```
bin/console app:import:criteria:rents --download
git diff resources/geo/
git add resources/geo/ && git commit
```

Without `--download`, the vendored file is imported as is. Each edition is a
separate dataset on data.gouv.fr: moving to a new one means updating the
edition year and the resource ids in the downloader first. The import also
re-runs when the housing file changes, since it holds the weights. It is an
idempotent upsert. Import the zones first (`make db-reset` runs everything in
the right order).
