# src/Command

A command invoked as `app:foo:bar` must be implemented as `App\Command\FooBarCommand`
in `src/Command/FooBarCommand.php`: strip the `app:` namespace segment, StudlyCase
the remaining colon-separated segments, join them, and suffix `Command`.

Register the name with `#[AsCommand(name: 'app:foo:bar')]` on the class (per root
AGENTS.md: attributes, not hand-wired config), extending
`Symfony\Component\Console\Command\Command`.

Context, rationale, caveats, usage notes — anything beyond the one-line
`description` — goes in the attribute's `help:` (shown by `--help`), not in a
class-level PHPDoc block. It's the same information either way; `help:` puts
it where whoever runs the command actually sees it.

Data imports live under the `app:import:*` namespace (`app:import:zones`,
`app:import:criteria:population`, ...). A command that works from a vendored
file takes a `--download` option to refresh that file from its source first,
rather than having a separate `download` command.
