<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\CriterionRepository;
use App\Service\Ranking\CriterionRankingProvider;
use App\Service\Ranking\RankingCatalog;
use App\Service\Ranking\SiteStatsProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/', name: 'app_home')]
final class HomeController extends AbstractController
{
    public function __construct(
        private readonly SiteStatsProvider $statsProvider,
        private readonly RankingCatalog $catalog,
        private readonly CriterionRankingProvider $rankingProvider,
        private readonly CriterionRepository $criterionRepository,
    ) {
    }

    public function __invoke(): Response
    {
        $podiums = [];
        foreach ($this->catalog->featured() as $ranking) {
            $criterion = $this->criterionRepository->findOneBy(['code' => $ranking->code]);
            if (null === $criterion) {
                continue;
            }

            $podiums[] = [
                'ranking' => $ranking,
                'criterion' => $criterion,
                'rows' => $this->rankingProvider->findRanking($ranking->code, $criterion->isRankFromLowest(), 3)['rows'],
            ];
        }

        return $this->render('home/index.html.twig', [
            'stats' => $this->statsProvider->find(),
            'podiums' => $podiums,
        ]);
    }
}
