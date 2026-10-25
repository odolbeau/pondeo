<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Ranking\Ranking;
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

    public function __invoke(
        #[MapQueryString] ScoreInput $input = new ScoreInput(),
        #[MapQueryString] UniteUrbaineFilterInput $filters = new UniteUrbaineFilterInput(),
    ): Response {
        $rankings = array_filter($this->catalog->all(), static fn (Ranking $ranking): bool => $ranking->scorable);
        $rankings = array_column(array_map(static fn (Ranking $ranking): array => [$ranking->code, $ranking], $rankings), 1, 0);

        $weights = array_intersect_key($input->weights(), $rankings);
        $inverted = array_keys(array_filter($rankings, static fn (Ranking $ranking): bool => $ranking->invertedInScore));
        $data = $this->scoreProvider->findScores($weights, self::PER_PAGE, ($input->page - 1) * self::PER_PAGE, $inverted, $filters->toFilter());

        $themes = [];
        foreach ($rankings as $ranking) {
            $themes[$ranking->theme][] = $ranking;
        }

        return $this->render('score/index.html.twig', [
            'themes' => $themes,
            'rankings' => $rankings,
            'weights' => $weights,
            'filterQuery' => $filters->toQuery(),
            'maxWeight' => ScoreInput::MAX_WEIGHT,
            'rows' => $data['rows'],
            'total' => $data['total'],
            'page' => $input->page,
            'pages' => max(1, (int) ceil($data['total'] / self::PER_PAGE)),
        ]);
    }
}
