# Deployment

Pondeo runs at <https://pondeo.bbnt.me>, on the `fourzitou` server. Every
merge to `main` deploys it (`.github/workflows/deploy.yml`).

## Pipeline

1. Two images are built and pushed to the GitHub Container Registry: `php`
   (the application and the data to import) and `web` (nginx and the static
   files), both from `.docker/prod/Dockerfile`.
2. The `production` tag is moved to the new images, then the compose file and
   the deploy script (`deploy/`) are uploaded to the server over SSH.
3. The deploy script pulls the images, resets the database and restarts the
   stack.
4. A smoke test requests the home page until it answers.

`gh workflow run deploy.yml -f version=<commit sha>` redeploys (or rolls back
to) an image already built.

## The database is rebuilt on every deployment

The data is the heart of the project and is entirely derived from the files
committed under `resources/geo`. Nothing is worth keeping in production: each
deployment drops the database and rebuilds it with the same procedure as in
development (`bin/reset-db`: migrations, then every import). The demo
fixtures are the only difference, they are not loaded in production.

The site is therefore unavailable while the imports run.

## Server side

The server only holds what is specific to it, managed in the `fourzitou`
repository with Ansible: a restricted `pondeo-deploy` user whose SSH key is
the CI one, the production `.env`, and a `compose.override.yaml` that attaches
the web container to the reverse proxy (Traefik), which handles TLS.

## GitHub configuration

The `production` environment of the repository holds the secrets
`SSH_PRIVATE_KEY` and `SSH_KNOWN_HOSTS`, and the variables `SSH_HOST`,
`DEPLOY_PATH` and `PRODUCTION_URL`.
