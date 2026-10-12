<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Ranking\RankingCatalog;
use App\Service\Ranking\WeightedScoreProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/mon-classement', name: 'app_score')]
final class UniteUrbaineScoreController extends AbstractController
{
    private const int PER_PAGE = 50;

    public function __construct(
        private readonly RankingCatalog $catalog,
        private readonly WeightedScoreProvider $scoreProvider,
    ) {
    }

    public function __invoke(#[MapQueryString] ScoreInput $input = new ScoreInput()): Response
    {
        $weights = array_intersect_key($input->weights(), array_flip($this->catalog->codes()));
        $data = $this->scoreProvider->findScores($weights, self::PER_PAGE, ($input->page - 1) * self::PER_PAGE);

        return $this->render('score/index.html.twig', [
            'themes' => $this->catalog->byTheme(),
            'rankings' => array_column(array_map(static fn (\App\Service\Ranking\Ranking $ranking): array => [$ranking->code, $ranking], $this->catalog->all()), 1, 0),
            'weights' => $weights,
            'maxWeight' => ScoreInput::MAX_WEIGHT,
            'rows' => $data['rows'],
            'total' => $data['total'],
            'page' => $input->page,
            'pages' => max(1, (int) ceil($data['total'] / self::PER_PAGE)),
        ]);
    }
}
