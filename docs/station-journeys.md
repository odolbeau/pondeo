# Station journeys

For each railway station (see `docs/poi-stations.md`) and each destination
city, the app stores how well the station is connected on a reference day:
how many trains a day, how long they take, and how many transfers it takes.
Destinations today: **Nantes**, **Paris**, **Lyon**, **Marseille**, **Bordeaux**,
**Toulouse** and **Lille**.

## What is stored

For one station and one destination, over the reference day:

- **Transfers:** the fewest transfers among all the day's journeys. `0` means
  there is a direct train. Journeys with more transfers than the fewest are
  ignored: a station with a direct train to Paris is described by its direct
  trains only, never by the slower journeys with a change.
- **Trains per day:** the number of distinct departures at that transfer
  level, from the station towards the destination (the way back is not
  counted). With transfers above 0, read it as "options per day": several
  journeys can share their first train.
- **Median duration** of those journeys, in minutes. The median is robust to
  the odd slow train; the durations of a day are usually close anyway.

When the destination cannot be reached with at most 3 transfers, or the
station is unknown to the API, the three figures are empty.

A station inside the destination has no journey to it: no line for Paris
stations towards Paris, nor for the stations of Lyon or Lille towards their
city, nor for the main station of Nantes, Marseille, Bordeaux or Toulouse
towards its city. The other stations of Nantes (Pirmil, Chantenay...) are genuine origins.

## Definitions to keep in mind

- **Paris** is any Paris station: the duration is to the first Paris station
  reached, whichever it is.
- **Nantes** is the main Nantes station only; **Marseille** is Saint-Charles
  only (Blancarde is a genuine origin); **Bordeaux** is Saint-Jean only;
  **Toulouse** is Matabiau only.
- **Lyon** is any station of the city (Part-Dieu, Perrache, Vaise...), like
  Paris: the duration is to the first one reached. Lyon-Saint-Exupéry TGV is
  outside the city, so it is a genuine origin. Likewise **Lille** is any
  station of the city (Flandres, Europe...).
- The whole day counts, 00:00 to 24:00, including very early and very late
  trains.
- The figures are a **snapshot of one day** (the reference day is stored with
  them): a Tuesday outside school holidays, so offers on weekends, holidays or
  during works differ. Planned timetables are used, not real-time disruptions.
- Coaches and buses are excluded when the data labels them as such, but some
  regional coach services are labelled as TER in the source and are counted.

## Source

The SNCF API (Navitia), `https://api.sncf.com`, authenticated by a token read
from `SNCF_API_TOKEN` (set it in `.env.local`, never commit it). The API only
serves about a month of timetables: the reference day must fall within the
period it currently publishes.

The feed is flagged "Private (unspecified)" and the token comes with terms of
use: read them before publishing or redistributing the derived figures.

## The vendored file

`resources/geo/trajets-gares.csv` (Git LFS) holds the API results, so the
database can be rebuilt without calling the API: `make db-reset` is enough. One
line per station and destination:

| Column           | Content                                                              |
|------------------|----------------------------------------------------------------------|
| `id_gare`        | the station's id in `gares-voyageurs.csv`                            |
| `destination`    | the destination, lowercase: `nantes`, `paris`, `lyon`...             |
| `reference_date` | the day read, `YYYY-MM-DD`                                           |
| `transfers`      | the fewest transfers; empty when out of reach                        |
| `departures`     | the day's departures at that level, `HHMM:minutes` separated by `;`   |

The file keeps the departures themselves, not only the figures, so the figures
can be derived differently (mean instead of median, another count) without
asking the API again.

## Refreshing

```
bin/console app:import:pois:stations:journeys --download
git diff --stat resources/geo/
git add resources/geo/ && git commit
```

`--download` reads the timetables from the API, then imports. It is a long job:
about 2.5 requests per station and destination, i.e. around 14 000 requests and
an hour or more, under an API quota whose size is not published. The file is the
checkpoint: pairs already computed for the same day are skipped, so an
interrupted run (Ctrl-C included) resumes where it stopped, and when the quota
is reached the command waits as long as needed and carries on by itself.

Useful options: `--date=YYYY-MM-DD` (another day; every pair is then recomputed),
`--station=<UIC code or station id>` (one station, to check a figure),
`--limit=N` (a pilot on the first N stations), `--force` (recompute pairs
already done for the day).

Run one download at a time: two would overwrite each other's progress. `--force`
is all-or-nothing: an interrupted forced run cannot be resumed, since re-running
without it skips the pairs already marked as done for the day.

Without `--download`, the command only imports the vendored file, offline. The
database mirrors the file: lines that are not in it, e.g. after a `--limit`
pilot on a fresh file, are removed.

## Where it is shown

The "closest stations" card of an urban unit (map side panel and full page, see
`docs/criteria-station-access.md`) lists, under each station, one line per
destination: transfers ("direct" or "1 correspondance"), trains per day and
median duration, or "no connection" when the destination is out of reach. A
station inside the destination has no line for it. A footnote gives the
reference day, since the figures are a snapshot.

## Map filter: time to a city

The map can keep only the urban units within a maximum time of one or more
destination cities (two rows in the sidebar; with several cities, a unit must
satisfy all of them). The time of a unit to a city is the best, over its
closest stations (see `docs/criteria-station-access.md`), of **the car ride to
the station plus the median train duration from that station to the city**. A
unit with no station of its own is therefore judged on its nearest ones.

- A station inside the destination has no journey: only the car ride counts, so
  the destination city itself always qualifies.
- A station that cannot reach the destination does not count; a unit left
  with no usable station is excluded, since it cannot be said to meet the limit.
- An optional maximum number of transfers drops the stations whose fewest
  transfers to a destination exceed it (`0`: direct trains only).
- Car times are theoretical and the train figures are the one-day snapshot
  above: good to compare cities, not to plan a trip.

## Adding a destination

A destination is a city the SNCF API can route to (a station or a whole city).
Add it to the list of destinations, then run the command with `--download`:
only the missing pairs are computed, for the same reference day.
