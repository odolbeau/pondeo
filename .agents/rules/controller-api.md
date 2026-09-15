# src/Controller/Api

API endpoints (JSON, not an HTML page) live under `src/Controller/Api/`, not
directly in `src/Controller/`. Everything in `controller.md` still applies
(one `__invoke()` action per class, route attribute on the class).

A DTO bound to an API endpoint via `#[MapQueryString]` / `#[MapRequestPayload]`
takes the controller's name with `Controller` replaced by `Input`, and lives
next to it in the same `src/Controller/Api/` directory — e.g.
`src/Controller/Api/CommuneMapDataController.php` pairs with
`src/Controller/Api/CommuneMapDataInput.php`. Keeps the DTO discoverable from
the endpoint it belongs to without having to grep for usages.
