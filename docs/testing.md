# Testing

`make test` runs PHPUnit against a dedicated test database (the `_test` suffix
of the application one), then checks that every route is exercised.

## Database isolation

The database is rebuilt from the migrations once, before the first test that
needs it. Each test then runs inside a transaction that is rolled back at the
end ([DAMA Doctrine Test Bundle](https://github.com/dmaicher/doctrine-test-bundle),
driven by Foundry), so tests do not see each other's data and the suite stays
fast.

Every test that reaches the database must use Foundry's `ResetDatabase` trait,
even if it does not create any data. A test that connects without it, and that
runs before the first one that has it, keeps a connection open while the
database is rebuilt, which makes most of the suite fail with "no connection to
the server".

## Why migrations, not the Doctrine mapping

Foundry rebuilds the database by running the migrations rather than by creating
the schema from the mapping, because the mapping alone cannot produce the
schema: the PostGIS extension and the `CHECK` on criterion values only exist
in the migrations, and the generated centroid columns come out of the mapping as
invalid SQL.
