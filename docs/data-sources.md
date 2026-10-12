# Data sources and licences

The code is under the MIT license (see `LICENSE`). The files under
`resources/geo` are **not**: they are derived from public sources and keep the
terms of their origin. This page lists them. Where the terms are not settled,
the status says so.

Status: **OK** = terms checked against what the project does, **To verify** =
to confirm before relying on it.

| Files | Source | Terms | Status |
|-------|--------|-------|--------|
| `population-historique`, `structure-age-recensement`, `diplomes-recensement`, `emploi-recensement`, `logement-recensement`, `pcs-recensement`, `pyramide-ages`, `revenus-filosofi`, `commerces-bpe` | INSEE, [Mélodi API](https://portail-api.insee.fr/) | Licence Ouverte; credit INSEE and the dataset | OK |
| `communes-aires-attraction` | INSEE, [aires d'attraction des villes 2020](https://www.insee.fr/fr/information/4803954) | Licence Ouverte; credit INSEE | OK |
| `revenus-fiscaux` | DGFiP, IRCOM, on data.gouv.fr | Licence Ouverte; credit DGFiP | OK |
| `prix-immobilier` | DGFiP / Etalab, Statistiques DVF, on data.gouv.fr | Licence Ouverte; credit DGFiP and Etalab | OK |
| `centrales-nucleaires` | EDF SA, on data.gouv.fr | Licence Ouverte; credit EDF | OK |
| `gares-voyageurs` | SNCF Gares & Connexions, on data.gouv.fr | [ODbL](https://opendatacommons.org/licenses/odbl/): credit SNCF, share derived databases under the same terms | OK, see below |
| `climat-normales`, `climat-projections` | [Open-Meteo](https://open-meteo.com/) | CC BY 4.0; credit shown in the app. The free API is for non-commercial use, so a commercial re-download needs a paid plan | OK |
| `risques-communes` | [Géorisques API](https://georisques.gouv.fr/api/v1) (Ministry of Ecological Transition, BRGM) | Open API; licence not recorded | To verify |
| `gares-acces-voiture` | Computed with the IGN Géoplateforme routing service (BD TOPO road network) | Documented as free of storage and reuse restrictions | To verify against the current Géoplateforme terms |
| `communes-unites-urbaines` | data.gouv.fr, "Communes et villes de France" | Licence not recorded | To verify |
| `communes.geojson` | Simplified [france-geojson](https://github.com/gregoiredavid/france-geojson) (IGN Admin Express) | Open licence, not recorded precisely | To verify |
| `trajets-gares` | SNCF API (Navitia), journeys computed from its timetables | The feed is flagged "Private (unspecified)" and the API token comes with terms of use | **To verify, blocking** |

## Points that need a decision

- **`trajets-gares`**: the SNCF API terms must allow publishing the derived
  figures. Until this is confirmed, the file should not be published, or
  should be replaced by a build step that every user runs with their own token.
- **ODbL (`gares-voyageurs`)**: share-alike applies to derived *databases*.
  Keeping the file's licence and credit with it, as this page does, is the
  minimum; if the project's own database is considered derived from it, that
  database would have to be offered under the ODbL too.
- **Open-Meteo**: anyone running the site commercially needs their own
  Open-Meteo plan to refresh the climate data.

## Attribution

Licence Ouverte and CC BY require crediting the source wherever the figures
are shown. The app currently credits Open-Meteo only; the per-criterion
sources in the glossary are the place to cover the others (see
`docs/criteria-glossary.md`).

Each criterion's page in `docs/` describes its source and how to refresh it.
