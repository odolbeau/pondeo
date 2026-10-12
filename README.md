# Pondeo

A website to compare French areas (cities, departments, regions, ...) according
to multiple criteria: income, housing, real estate prices, climate, risks,
commerces, access to train stations, and more. Everything is built from public
data.

## Installation

**Requirements:** run `bin/doctor` to check.

```bash
make install
```

Then visit `https://<directory name>.pondeo.localhost`, printed at the end.

Each git worktree runs its own stack: `make serve` starts the current one.
See [`docs/local-environment.md`](docs/local-environment.md), including the
machine-wide dev proxy it requires. Run `make help` to list the other commands.

## Stack

Symfony 8.1 on PHP 8.5, PostgreSQL with PostGIS, Doctrine, Twig, Stimulus and
Turbo, served through Docker Compose.

## Data

The data is imported from the files committed under `resources/geo`, which are
derived from public sources (INSEE, data.gouv.fr, Open-Meteo, SNCF, ...). Each
criterion's source and import are described in [`docs/`](docs/), starting with
[`docs/criteria-import.md`](docs/criteria-import.md).

## Deployment

Every merge to `main` deploys the site and updates its database in place.
See [`docs/deployment.md`](docs/deployment.md).

## Contributing

See [`CONTRIBUTING.md`](CONTRIBUTING.md).

## License

The code is released under the [MIT License](LICENSE). The data under
`resources/geo` stays under the licence of its original source, see the
documentation of each criterion in [`docs/`](docs/).
