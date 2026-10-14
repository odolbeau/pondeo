# Deployment

Pondeo runs at <https://pondeo.bbnt.me>. Every
merge to `main` deploys it (`.github/workflows/deploy.yml`).

## Pipeline

1. Two images are built and pushed to the GitHub Container Registry: `php`
   (the application and the data to import) and `web` (nginx and the static
   files), both from `.docker/prod/Dockerfile`.
2. The `production` tag is moved to the new images, then the compose file and
   the deploy script (`deploy/`) are uploaded to the server over SSH.
3. The deploy script pulls the images, updates the database in place and
   restarts the stack.
4. A smoke test requests the home page until it answers.

`gh workflow run deploy.yml -f version=<commit sha>` redeploys (or rolls back
to) an image already built.

## Data is updated in place on every deployment

The data is the heart of the project and is entirely derived from the files
committed under `resources/geo`. Each deployment applies the pending
migrations, then re-runs the imports (`bin/import-data`). The imports are
idempotent upserts: existing rows are updated, missing ones added, and nothing
is dropped. The site stays up meanwhile, still running the previous release
on the migrated schema, so migrations must stay backward compatible.

An import whose source files, and the zones it depends on, are unchanged since
its last successful run is skipped, so a deployment that does not touch the
data only pays for the migrations. Changing an import's command re-runs that
import, and only it. The code the command delegates to is not tracked: after
changing it, touch the command (or rebuild with `make db-reset`) to re-run the import.
`--download` runs an import regardless, and `make db-reset` rebuilds everything.

## Server side

The server only holds what is specific to it: a restricted `pondeo-deploy`
user whose SSH key is the CI one, the production `.env`, and a
`compose.override.yaml` that attaches the web container to the reverse proxy
in front, which handles TLS.

## GitHub configuration

The `production` environment of the repository holds the secrets
`SSH_PRIVATE_KEY` and `SSH_KNOWN_HOSTS`, and the variables `SSH_HOST`,
`DEPLOY_PATH` and `PRODUCTION_URL`.
