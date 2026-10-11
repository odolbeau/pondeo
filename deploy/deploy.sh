#!/bin/bash
#
# Deploys the images tagged PONDEO_IMAGE_TAG (see compose.yaml): pulls them,
# rebuilds the database from scratch (the data is imported from the image,
# nothing is worth keeping) then (re)starts the stack. Run from the directory
# holding compose.yaml and its `.env` (plus any compose.override.yaml, which
# docker compose reads on its own).
#
# Usage: deploy.sh <registry-user> < registry-token
#
# The token is read on stdin, never passed as an argument, so that it shows up
# neither in the process list nor in the shell history.

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")"

docker login ghcr.io --username "$1" --password-stdin
trap 'docker logout ghcr.io >/dev/null' EXIT

docker compose pull --quiet

# No connection left on the database while it is dropped.
docker compose stop php web
docker compose up --detach --wait database
docker compose run --rm php bin/reset-db

docker compose up --detach --remove-orphans --wait
docker image prune --force
