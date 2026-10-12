# Contributing

Thanks for your interest in Pondeo! Issues and pull requests are welcome.

## Getting started

Follow the installation steps of the [README](README.md), then start the stack
with `make serve`. See [`docs/local-environment.md`](docs/local-environment.md)
for details.

## Before opening a pull request

Run:

```bash
make sanitize-and-check
```

It runs Rector, PHP-CS-Fixer, PHPStan and the tests; fix whatever it flags.
CI runs the same checks.

- **Tests**: a feature comes with a test that exercises it the way a caller
  would (an HTTP request for a controller, a service call for a service).
  Functional tests extend `WebTestCase`, service tests `KernelTestCase`.
- **Code style**: see [`.agents/rules/README.md`](.agents/rules/README.md).
  Follow the [Symfony best practices](https://symfony.com/doc/current/best_practices.html):
  attributes, autowiring, thin controllers.
- **Schema changes** go through migrations (`bin/console make:migration`),
  never hand-written SQL. Migrations must stay backward compatible, since the
  previous release keeps running while a deployment migrates the database.
- **Documentation** lives under [`docs/`](docs/), in English, one topic per
  file.
- **Commits** follow [Conventional Commits](https://www.conventionalcommits.org/)
  (`feat:`, `fix:`, `chore:`, ...).

## Data

Data files under `resources/geo` are committed, not fetched at deployment.
When adding a data source, document its origin and licence in `docs/`.

## License

By contributing, you agree that your contributions are licensed under the
[MIT License](LICENSE).
