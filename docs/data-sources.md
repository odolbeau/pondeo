# Data sources and licences

The code is under the MIT license (see `LICENSE`). The files under
`resources/geo` are **not**: they are derived from public sources and keep the
terms of their origin. This page lists them. Where the terms are not settled,
the status says so.

Status: **OK** = terms checked against what the project does, **To verify** =
to confirm before relying on it.

| Files | Source | Terms | Status |
|-------|--------|-------|--------|
| `population-historique`, `structure-age-recensement`, `diplomes-recensement`, `emploi-recensement`, `logement-recensement`, `pcs-recensement`, `pyramide-ages`, `revenus-filosofi`, `equipements-bpe`, `equipements-bpe-types` (dataset `DS_BPE_EVOLUTION`, see [equipements-bpe.md](equipements-bpe.md)) | INSEE, [Mélodi API](https://portail-api.insee.fr/) | Licence Ouverte; credit INSEE and the dataset | OK |
| `associations-rna` | Ministry of the Interior, [Répertoire national des associations](https://www.data.gouv.fr/datasets/repertoire-national-des-associations), on data.gouv.fr | Licence Ouverte; credit the Ministry of the Interior | OK |
| `communes-aires-attraction` | INSEE, [aires d'attraction des villes 2020](https://www.insee.fr/fr/information/4803954) | Licence Ouverte; credit INSEE | OK |
| `revenus-fiscaux` | DGFiP, IRCOM, on data.gouv.fr | Licence Ouverte; credit DGFiP | OK |
| `prix-immobilier` | DGFiP / Etalab, Statistiques DVF, on data.gouv.fr | Licence Ouverte; credit DGFiP and Etalab | OK |
| `loyers-annonces` | ANIL, [Carte des loyers 2025](https://www.data.gouv.fr/datasets/carte-des-loyers-indicateurs-de-loyers-dannonce-par-commune-en-2025), on data.gouv.fr (see [criteria-rents.md](criteria-rents.md)) | Licence Ouverte 2.0; credit "Estimations ANIL, à partir des données du Groupe SeLoger et de leboncoin" | OK |
| `centrales-nucleaires` | EDF SA, on data.gouv.fr | Licence Ouverte; credit EDF | OK |
| `jeux-associations`, `jeux-boutiques` | [Subverti](https://subverti.com/fr/maps/associations/): export of its directory of board game clubs, ludothèques, associations, shops and bars, provided by the publisher. Reduced copies are vendored (no contact details) | [CC BY-SA 4.0](https://creativecommons.org/licenses/by-sa/4.0/deed.fr): credit Subverti, and share the adapted files under the same licence (the project's code keeps its own) | OK |
| `sites-seveso` | [Géorisques](https://georisques.gouv.fr/) "Établissements Seveso - France métropolitaine" (Ministry of Ecological Transition, BRGM), a WFS layer listed on data.gouv.fr (see [poi-seveso-sites.md](poi-seveso-sites.md)) | Licence Ouverte 2.0 per the Géorisques [terms of use](https://www.georisques.gouv.fr/cgu) (the dataset's data.gouv.fr page leaves the licence unspecified); credit the producer and the date of the data | OK |
| `sites-pollues`, `etablissements-pollueurs` | [Géorisques](https://georisques.gouv.fr/) WFS layers "Secteurs d'information sur les sols" (SIS) and "Registre des émissions polluantes" (IREP) (Ministry of Ecological Transition, BRGM) (see [poi-polluted-sites.md](poi-polluted-sites.md)) | Licence Ouverte 2.0 per the Géorisques [terms of use](https://www.georisques.gouv.fr/cgu); credit the producer and the date of the data | OK |
| `musees` | Ministry of Culture, [Répertoire des Musées de France (base Muséofile)](https://www.data.gouv.fr/datasets/musees-de-france-base-museofile), on data.gouv.fr (see [poi-museums.md](poi-museums.md)) | Licence Ouverte; credit the Ministry of Culture | OK |
| `hopitaux` (see [poi-hospitals.md](poi-hospitals.md)) | Agence du Numérique en Santé, [FINESS structures and activities](https://www.data.gouv.fr/datasets/finess-structures-1), on data.gouv.fr | Licence Ouverte 2.0; credit the Ministry of Health and the Agence du Numérique en Santé | OK |
| `arrets-tram-metro` | IGN, [BD TOPO](https://geoservices.ign.fr/bdtopo) (layer "équipement de transport"), Géoplateforme WFS | Licence Ouverte; credit IGN. See [poi-transit-stops.md](poi-transit-stops.md) | OK |
| `gares-voyageurs` | SNCF Gares & Connexions, on data.gouv.fr | [ODbL](https://opendatacommons.org/licenses/odbl/): credit SNCF, share derived databases under the same terms | OK, see below |
| `climat-normales`, `climat-projections` | [Open-Meteo](https://open-meteo.com/) | CC BY 4.0; credit shown in the app. The free API is for non-commercial use, so a commercial re-download needs a paid plan | OK |
| `risques-communes` | [Géorisques API](https://georisques.gouv.fr/api/v1) (Ministry of Ecological Transition, BRGM) | Licence Ouverte 2.0 per the [terms of use](https://www.georisques.gouv.fr/cgu); credit the producer and the date of the data | OK |
| `gares-acces-voiture` | Computed with the IGN Géoplateforme routing service (BD TOPO road network) | Free of charge, commercial use included; the [terms](https://cartes.gouv.fr/cgu/) set no storage or redistribution restriction and defer to the data's own licence (Licence Ouverte by default). Credit IGN | OK |
| `communes-unites-urbaines` | data.gouv.fr, "Communes et villes de France" (third-party compilation of INSEE data; only INSEE's commune codes, names and urban units are kept) | Licence Ouverte 2.0; credit INSEE | OK |
| `communes.geojson` | Simplified [france-geojson](https://github.com/gregoiredavid/france-geojson) (IGN Admin Express COG, 2018 edition) | Admin Express terms (Licence Ouverte); the repository has no licence of its own | To verify: regenerate from Admin Express or keep and credit |
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
