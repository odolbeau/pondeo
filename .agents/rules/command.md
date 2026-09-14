# src/Command

A command invoked as `app:foo:bar` must be implemented as `App\Command\FooBarCommand`
in `src/Command/FooBarCommand.php`: strip the `app:` namespace segment, StudlyCase
the remaining colon-separated segments, join them, and suffix `Command`.

Register the name with `#[AsCommand(name: 'app:foo:bar')]` on the class (per root
AGENTS.md: attributes, not hand-wired config), extending
`Symfony\Component\Console\Command\Command`.
