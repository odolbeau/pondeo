# Local environment

Every git worktree runs its own isolated stack, so several branches can be
served side by side. `/serve` (Claude Code) or `make serve` starts the current
worktree's stack and prints its URL.

## One stack per worktree

The Docker Compose project is named after the worktree's directory, which
gives each worktree its own containers, volumes and hostname:

| Worktree directory | URL                                |
|--------------------|------------------------------------|
| `<dir>`            | `https://<dir>.pondeo.localhost`   |

Nothing is published on the host. `*.localhost` resolves to the local machine
by itself, so no DNS or `/etc/hosts` entry is needed.

## Database

Each worktree has its own database (its own Compose volume): branches with
different migrations never step on each other. `make serve` brings it up to date
(`make db-update`): pending migrations, then the imports whose sources changed,
which on a new volume means all of them. Nothing is dropped. `make db-reset`
rebuilds it from scratch.

## Without the dev proxy

`make serve-standalone` starts the same stack without the proxy, published on
<http://localhost:8080> (`compose.standalone.yaml`). It suits a single
checkout, or anyone who doesn't want to set the proxy up. Two stacks can't
run in this mode at once, as they would compete for the port.

## Dev proxy

HTTPS is terminated by a reverse proxy (Traefik) that is **machine-wide
infrastructure shared by all local projects**, not part of this repository.
It owns host ports 80 and 443 and discovers containers through Docker labels.
The contract with this project:

- A Docker network named `dev-proxy`, which the proxy and the project's web
  container (`nginx`) are both attached to. The project declares it as
  external; it never creates it.
- Routing is declared by Traefik labels on `nginx` (see `compose.yaml`): the
  hostname above, TLS enabled, plain HTTP to the container. The app trusts
  the proxy's `X-Forwarded-*` headers (`TRUSTED_PROXIES`) so generated URLs
  are `https://`.
- Certificates are dropped by the project in the proxy's directory
  (`~/.local/share/dev-proxy`, or `$DEV_PROXY_DIR`): the certificate and key
  in `certs/`, and a Traefik file-provider snippet in `dynamic/`. The proxy
  watches that directory, so no restart is needed. `bin/certs` does this, and
  `make serve` runs it when the certificate is missing.

`make serve` refuses to start, and says why, when the proxy isn't running.

## Trusted certificate

With [mkcert](https://github.com/FiloSottile/mkcert) installed, the
certificate is signed by a local certificate authority that `bin/doctor`
makes browsers trust (it asks for sudo once). Without it the certificate is
self-signed and browsers show a warning. Install mkcert later, then re-run
`bin/doctor`: it regenerates the certificate.

## Front-end dev server

The `node` container runs the Vite dev server, which watches the project:
a changed template, stylesheet or Stimulus controller is picked up within a
second, with no restart. Only `vendor/`, `var/` and `.git/` of the project are
left out of the watch: the patterns are anchored on the project's own path,
because a bare `**/var/**` would match the container's `/var/www` and ignore
every file.

## Archiving a worktree

[Orca](../orca.yaml) runs `make destroy` when a worktree is archived, which
removes its containers, volumes and images if the stack was ever started.
