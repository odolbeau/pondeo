# Education

Each urban unit (see `docs/urban-units.md`) carries the share of
higher-education graduates among its inhabitants, under the "Diplômes" group
of its detail page (see `docs/urban-unit-detail.md`), with the usual rank,
median of all urban units and evolution chart.

## Source

INSEE's census, "Diplômes et formation" dataset, through the
[Mélodi API](https://portail-api.insee.fr/) (dataset `DS_RP_DIPLOMES_PRINC`,
no authentication, open licence). Figures exist for the **census years only**
(2012, 2017, 2023).

## Criterion

| Criterion | Meaning |
|---|---|
| Part de diplômés du supérieur | Inhabitants whose highest degree is post-baccalauréat (bac+2 and above), over the people aged 15 or more who have left school, in % |

Students are not counted in the base (INSEE only qualifies people who have
finished their studies), so a student city is not penalized, but it does not
show its students either.

- **Counts:** the graduates and the base are summed over the communes of each
  urban unit (communes outside every urban unit are skipped). They are only a
  means to the share and are not kept.
- **Share:** derived from those sums, never summed. A unit without a base has
  no share.

## Refreshing

```
bin/console app:import:criteria:education --download
git diff resources/geo/
git add resources/geo/ && git commit
```

Without `--download`, the vendored file is imported as is. The import is an
idempotent upsert. Import the zones first (`make db-reset` runs everything in
the right order).
