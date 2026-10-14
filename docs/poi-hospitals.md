# Hospitals

The hospitals are stored as points of interest (POIs), an establishment being
one POI whether or not it has an emergency department. They show on the detail
page of the urban unit that contains them (see
[urban-unit-detail.md](urban-unit-detail.md)), in the "Points d'intérêt"
section, with "Urgences" next to the name of those with an emergency
department. No map filter uses them yet.

## Source

The French health establishments directory,
[FINESS](https://www.data.gouv.fr/datasets/finess-structures-1), in its
next-generation open data feeds published by the Agence du Numérique en Santé
on data.gouv.fr, under the
[Licence Ouverte](https://www.etalab.gouv.fr/licence-ouverte-open-licence/):

- **Structures**: the geographic establishments, with their category, name,
  address and position.
- **Activities**: the activities each establishment is authorised to practise,
  among which emergency medicine.

The older "Extraction du Fichier des établissements" is frozen since May 2026
and is not used. Only structures are kept: the directory's professionals are
not in these feeds, and nobody is named as a person.

## What is kept

An open geographic establishment whose FINESS category is one of these:

| Category | Label |
|---|---|
| `hospital_university` | Centre hospitalier régional ou universitaire |
| `hospital_general` | Centre hospitalier (including local hospitals) |
| `hospital_clinic` | Clinique ou autre établissement de soins (multidisciplinary, surgical, medical, obstetric establishments) |
| `hospital_cancer` | Centre de lutte contre le cancer |
| `hospital_army` | Hôpital des armées |

All are children of the parent category "Hôpital ou clinique" (`hospital`), itself under "Santé" (`health_site`).
Dialysis, psychiatry, rehabilitation, long-stay, home hospitalisation, nursing
homes and the like are left out: they are not what is meant by "the nearest
hospital".

## Emergency departments

An establishment authorised to run a **"structure des urgences"** (the
"Médecine d'urgence" activity) is flagged `emergency` in the attributes of its
POI. It is the same POI, not a second one: the place is imported once.

- A department dedicated to children is recognised: its POI also carries the
  attribute `pediatric`.
- SAMU, SMUR and "antennes" are not emergency departments and are not kept.
- The feed has no category for the emergency department itself: it is the
  authorisation that is read, and only the authorisations still active.

## Vendored file

`resources/geo/hopitaux.csv` (Git LFS) has one row per establishment:

`finess` (the establishment's FINESS number, its identity), `name`, `type`
(`chu`, `ch`, `clinic`, `cancer`, `army`), `lat`, `lon` (WGS84), `address`,
`emergency` and `emergency_pediatric` (`1` or `0`).

- **Position:** the one the directory geocoded to the address (WGS84). When it
  has none, the position it gives in the Lambert-93 projection is converted. An
  establishment with neither, or with a position in an overseas projection
  (which the feed does not label), is left out: the download says how many.
- **Names** are the directory's, in capitals.
- **Unité urbaine:** each POI is attached to the unité urbaine containing it, if
  any. An establishment outside every unité urbaine is kept, attached to none.

## Refreshing

`app:import:pois:hospitals` imports the file, idempotently: a re-run updates
in place, moves an establishment whose type changed, and drops the ones the
file no longer lists. With `--download`, the file is first rebuilt from the
latest daily feeds (about 110 MB to download); review the result with
`git diff` before committing it.
