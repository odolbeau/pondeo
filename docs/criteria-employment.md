# Employment

Each urban unit (see `docs/urban-units.md`) carries its unemployment rate and
activity rate, under the "Emploi" group of its detail page (see
`docs/urban-unit-detail.md`), with the usual rank and median of all urban units.

## Source

INSEE's census, "Population active et chômage" dataset, through the
[Mélodi API](https://portail-api.insee.fr/) (dataset `DS_RP_EMPLOI_LR_PRINC`,
no authentication, open licence). Population aged 15-64, both sexes.

The census is a rolling survey: figures exist for the **census years only**
(2012, 2017, 2023), not every year. The unemployment is the census
(ILO-style self-declared) one, so it differs from the quarterly rate INSEE
publishes for zones d'emploi and from Pôle emploi / France Travail registrations.

## Criteria

| Criterion | Meaning |
|---|---|
| Taux de chômage | Unemployed / active population, in % |
| Taux d'activité | Active population / population aged 15-64, in % |

- **Counts:** the active, unemployed and 15-64 populations are summed over
  the communes of each urban unit (communes outside every urban unit are
  skipped; census estimates are fractional at commune level and rounded once,
  after summing). They are only a means to the rates and are not kept.
- **Rates:** derived from those sums, never summed. A unit without a 15-64
  population has no rate.

## Refreshing

```
bin/console app:import:criteria:employment --download
git diff resources/geo/
git add resources/geo/ && git commit
```

Without `--download`, the vendored file is imported as is. The import is an
idempotent upsert. Import the zones first (`make db-reset` runs everything in
the right order).
