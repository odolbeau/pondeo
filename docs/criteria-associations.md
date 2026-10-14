# Associations

Each urban unit (see `docs/urban-units.md`) carries the number of associations
based in it and their density per 1000 inhabitants, under the "Vie associative"
group of its detail page (see `docs/urban-unit-detail.md`). Like every
criterion, they get a rank and the median of all urban units, to place a city.

On the detail page the counts have no row of their own: each density is shown
with its count in the title, e.g. "Associations actives (3 120)", the density
as the value, and the unit's rank and the median next to it. The counts are not
ranked.

A ranking, "Les plus associatives" (see `docs/rankings.md`), orders the units
by the density of active associations. It is not part of the personal ranking
(see `docs/personal-ranking.md`).

## Source

The Répertoire national des associations (RNA), published by the Ministry of
the Interior on [data.gouv.fr](https://www.data.gouv.fr/datasets/repertoire-national-des-associations)
under the Licence Ouverte. A new monthly extract is published; the data is the
situation at its date, stored under that year as the period.

An association is counted in the commune of its **registered office**, which is
not always where it acts.

## Criteria

| Count | Density per 1000 inhabitants |
|---|---|
| All associations (every one still listed as active in the register) | yes |
| Active associations (see below) | yes |
| Active associations by family (see below) | yes |

- **Active:** the register learns that an association has stopped only when
  someone declares it, so it keeps many dormant ones. An association is
  counted as active when its last declaration (its creation, or a later
  amendment) is within the last 10 calendar years. On the 2026 extract,
  the ranking of the units by this density correlates at 0.81 with the one by
  all associations, and the share of recent declarations goes from about 53 %
  to 80 % across units: the filter changes the picture, which is why both are
  kept.
- **Families:** the register classifies each association by its social object.
  They are grouped into sport and outdoors, culture and heritage, leisure and
  neighbourhood life, solidarity and social action, health, education and
  research, environment. The other themes (politics, trade representation,
  religion…) have no family and only count in the totals. A family is only
  counted among active associations.
- **Count:** summed over the communes of each urban unit; communes outside
  every urban unit are skipped.
- **Density:** count divided by the unit's population, times 1000. It is
  computed from the stored values, never summed. The population used is the
  latest year not after the count's. A unit without population has no density.

## Coverage and limits

- **Bas-Rhin, Haut-Rhin and Moselle** are not covered: their associations are
  kept in the local registers of the courts, and the RNA hardly lists them. Their
  units have no value, rather than a misleading zero. The few communes of those
  departments that belong to a unit of another department are missing from its
  count.
- **Registered office:** small towns that serve a whole rural area host the
  associations of their surroundings and come out on top; the densities say as
  much about a town's role as about its own life.
- A commune that has never had an association of a family has no value for it.

## Refreshing

```
bin/console app:import:criteria:associations --download
git diff resources/geo/
git add resources/geo/ && git commit
```

Without `--download`, the vendored file is imported as is. `--download` fetches
the latest extract (about 400 MB) and counts it on the fly; only the counts per
commune are kept. The import is an idempotent upsert. Import the zones and the
population first (`make db-reset` runs everything in the right order).
