#!/bin/bash
#
# Deploys the images tagged PONDEO_IMAGE_TAG (see compose.yaml): pulls them,
# applies the migrations and re-runs the idempotent imports (the data comes
# from the image, nothing is dropped) then (re)starts the stack. Run from the directory
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

docker compose up --detach --wait database
docker compose run --rm php bin/import-data

docker compose up --detach --remove-orphans --wait
docker image prune --force
