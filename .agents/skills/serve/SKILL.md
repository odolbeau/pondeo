---
name: serve
description: Start this worktree's docker stack on demand (containers, dependencies, database brought up to date) behind the machine-wide dev proxy and report its HTTPS URL. Use when asked to serve, start, or run the app, or for its URL.
---

Run `make serve` from the repository root. It's idempotent: it starts this
worktree's stack, installs dependencies, and brings the database up to date (migrations,
then the imports whose sources changed; all of them on a new database).

It can take a few minutes on a worktree's first run (image build,
`composer install`, `npm install`, data import): run it in the background and
wait for it to finish rather than polling.

When it's done, report the URL it prints last. If it fails, show the failing
step's output — don't retry blindly. If it says the dev proxy isn't running,
that's machine-wide infrastructure outside this repository: point to
`docs/local-environment.md`.
