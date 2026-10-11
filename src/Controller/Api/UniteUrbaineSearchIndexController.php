<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Repository\UniteUrbaineRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Every unité urbaine as `[code, name, département]`, loaded once by the
 * search box and filtered in the browser.
 */
#[Route('/recherche/index', name: 'app_search_index')]
final class UniteUrbaineSearchIndexController extends AbstractController
{
    public function __construct(
        private readonly UniteUrbaineRepository $uniteUrbaineRepository,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        $index = array_map(
            static fn (array $row): array => [$row['code'], $row['name'], $row['departement']?->label()],
            $this->uniteUrbaineRepository->findSearchIndex(),
        );

        $response = new JsonResponse($index);
        $response->setPublic();
        $response->setMaxAge(3600);

        return $response;
    }
}
