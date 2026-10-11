# Database schema diagram

`bin/console app:db:dbml` exports the schema as [DBML](https://dbml.dbdiagram.io/home/),
which [dbdiagram.io](https://dbdiagram.io) renders as an entity-relationship diagram.

```bash
docker compose run --rm php bin/console app:db:dbml > schema.dbml   # or --output=schema.dbml
```

Paste the result into the dbdiagram.io editor.

The export is derived from the Doctrine mapping, not from a live database, so it needs no
connection and always matches the entities. The flip side: constraints written by hand in
migrations (such as the `CHECK` on `criterion_value`) are not part of it.
