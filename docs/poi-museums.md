# Museums

The "Musées de France", the museums holding the label of that name, are stored
as points of interest (POIs), in the "Musée de France" category (`museum`),
under the "Culture" category (see [poi-categories.md](poi-categories.md)).

## Source

The Ministry of Culture's directory of the Musées de France (base Muséofile),
on data.gouv.fr, under the Licence Ouverte. Terms: see
[data-sources.md](data-sources.md).

- **Kept:** the name, the address, the position and the thematic domains of
  each museum, shown next to its name (e.g. "Histoire, Technique et industrie").
  A museum without coordinates is left out.
- **Not kept:** the description, the contacts and the rest of the file.

The file is vendored under `resources/geo/` (Git LFS), like the other
reference data.

## Unité urbaine

Each museum is attached to the urban unit whose boundary contains it, if any
(see `docs/urban-units.md`). They show on the detail page of the unit, among
the points of interest (see [urban-unit-detail.md](urban-unit-detail.md)).

## Refreshing

```
bin/console app:import:pois:museums --download
git diff resources/geo/
git add resources/geo/ && git commit
```

`--download` refreshes the vendored file, then imports it. The import is an
idempotent upsert that removes the museums the source no longer lists. Import
the zones first (`app:import:zones`); `make db-reset` does both in the right
order.
