<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Controller\ScoreInput;
use App\Controller\UniteUrbaineFilterInput;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Serializer\Exception\ExceptionInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Sends a page that takes the filters, or the importances of the personal
 * ranking, to the same page with the last ones the visitor used in its address.
 * The visitor's browser keeps them in cookies; the address stays the state of
 * the page, so what is seen is what a copied link gives. An address that
 * already holds some wins over the cookie, and a cookie that is not valid is
 * ignored, as a page would reject the same query.
 */
final readonly class RestoreSavedStateListener
{
    public const string FILTERS_COOKIE = 'pondeo_filters';

    public const string WEIGHTS_COOKIE = 'pondeo_weights';

    /** The routes that take the filters. */
    private const array FILTER_ROUTES = ['app_map', 'app_ranking', 'app_score'];

    public function __construct(
        private DenormalizerInterface $denormalizer,
        private ValidatorInterface $validator,
    ) {
    }

    #[AsEventListener]
    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $route = $request->attributes->get('_route');
        if (!$event->isMainRequest() || !$request->isMethod('GET') || !\in_array($route, self::FILTER_ROUTES, true)) {
            return;
        }

        $query = $request->query->all();
        $restored = [];
        if (!$this->hasAny($query, UniteUrbaineFilterInput::parameterNames())) {
            $restored += $this->saved($request, self::FILTERS_COOKIE, UniteUrbaineFilterInput::class, UniteUrbaineFilterInput::parameterNames());
        }

        if ('app_score' === $route && !isset($query['w'])) {
            $restored += $this->saved($request, self::WEIGHTS_COOKIE, ScoreInput::class, ['w']);
        }

        if ([] === $restored) {
            return;
        }

        $response = new RedirectResponse($request->getPathInfo().'?'.http_build_query($query + $restored));
        // It depends on a cookie: no shared cache may keep it.
        $response->setPrivate();
        $response->headers->set('Vary', 'Cookie');

        $event->setResponse($response);
    }

    /**
     * @param array<string, mixed> $query
     * @param list<string>         $names
     */
    private function hasAny(array $query, array $names): bool
    {
        return [] !== array_intersect(array_keys($query), $names);
    }

    /**
     * @param class-string $dto   the query's DTO, which tells whether the cookie holds a valid query
     * @param list<string> $names the parameters of the DTO the cookie may set
     *
     * @return array<string, mixed>
     */
    private function saved(Request $request, string $cookie, string $dto, array $names): array
    {
        $value = $request->cookies->get($cookie);
        if (!\is_string($value) || '' === $value) {
            return [];
        }

        parse_str($value, $saved);
        $saved = array_intersect_key($saved, array_flip($names));
        if ([] === $saved) {
            return [];
        }

        try {
            $input = $this->denormalizer->denormalize($saved, $dto, 'csv', ['filter_bool' => true]);
        } catch (ExceptionInterface|\TypeError) {
            return [];
        }

        return 0 === \count($this->validator->validate($input)) ? $saved : [];
    }
}
