<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Criterion;
use App\Entity\UniteUrbaine;
use App\Service\Detail\CriterionSeriesProvider;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Series behind the chart a criterion row unfolds on the unité urbaine detail page.
 */
#[Route('/unites-urbaines/{code}/criteres/{criterion}/serie', name: 'app_unite_urbaine_criterion_series', requirements: ['code' => '[0-9A-Za-z]{5}', 'criterion' => '[a-z0-9_]+'])]
final class UniteUrbaineCriterionSeriesController extends AbstractController
{
    public function __construct(private readonly CriterionSeriesProvider $seriesProvider)
    {
    }

    public function __invoke(
        #[MapEntity(mapping: ['code' => 'inseeCode'])]
        UniteUrbaine $uniteUrbaine,
        #[MapEntity(mapping: ['criterion' => 'code'])]
        Criterion $criterion,
    ): JsonResponse {
        $series = $this->seriesProvider->find($uniteUrbaine, $criterion);
        if (null === $series) {
            throw $this->createNotFoundException('No value of this criterion for this unité urbaine.');
        }

        return $this->json($series);
    }
}
