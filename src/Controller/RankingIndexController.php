<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\CriterionRepository;
use App\Service\Ranking\CriterionRankingProvider;
use App\Service\Ranking\RankingCatalog;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/classements', name: 'app_rankings')]
final class RankingIndexController extends AbstractController
{
    public function __construct(
        private readonly RankingCatalog $catalog,
        private readonly CriterionRankingProvider $rankingProvider,
        private readonly CriterionRepository $criterionRepository,
    ) {
    }

    public function __invoke(): Response
    {
        $themes = [];
        foreach ($this->catalog->byTheme() as $theme => $rankings) {
            foreach ($rankings as $ranking) {
                $criterion = $this->criterionRepository->findOneBy(['code' => $ranking->code]);
                if (null === $criterion) {
                    continue;
                }

                $themes[$theme][] = [
                    'ranking' => $ranking,
                    'criterion' => $criterion,
                    'rows' => $this->rankingProvider->findRanking($ranking->code, $criterion->isRankFromLowest(), 3)['rows'],
                ];
            }
        }

        return $this->render('ranking/index.html.twig', ['themes' => $themes]);
    }
}
