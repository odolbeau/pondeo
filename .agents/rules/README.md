# Code style

Run `make cs-fix` to correct all files.

Comments are for the *why* — a non-obvious constraint, a tradeoff, a gotcha
that bit someone. Don't narrate the *what* when the code already says it.
Keep them short; a paragraph explaining something the reader can get from the
code and a couple of words is a sign to cut it, not polish it.

Prefer return-early over nested/chained conditionals. In particular, avoid
`?? throw`:

```php
// Avoid
return $this->entityManager->getRepository(Criterion::class)->find($id)
    ?? throw new \LogicException("Criterion {$id} not found.");

// Prefer
$criterion = $this->entityManager->getRepository(Criterion::class)->find($id);
if (null === $criterion) {
    throw new \LogicException("Criterion {$id} not found.");
}

return $criterion;
```

## Rules index

Code style rules (below) apply project-wide. Directory-scoped rules (naming,
structure, …) are listed in the table — before working on a file, look up its
directory here, a matching entry adds to (or overrides) the root `AGENTS.md`
for that path.

| Path             | File            | Summary                                                  |
|------------------|-----------------|-----------------------------------------------------------|
| `src/Command`    | `command.md`    | Console command class/file naming from the command name |
| `src/Controller` | `controller.md` | Invokable-only controllers, one action per class          |

