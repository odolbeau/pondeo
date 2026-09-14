# src/Controller

One action per controller: a single `public function __invoke(...): Response`,
not several named action methods on one class. Route attribute goes on the
class, not on `__invoke()` — corollary of being single-action:

```php
#[Route('/', name: 'app_home')]
final class HomeController extends AbstractController
{
    public function __invoke(): Response
```

Name the class after the action, not a resource with a suffix — e.g.
`HomeController`, `RegisterUserController` — and put each in its own file
under `src/Controller/`.
