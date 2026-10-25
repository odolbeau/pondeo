<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\CriterionRepository;
use App\Service\Ranking\CriterionRankingProvider;
use App\Service\Ranking\RankingCatalog;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/classements/{code}', name: 'app_ranking', requirements: ['code' => '[a-z0-9_]+'])]
final class RankingController extends AbstractController
{
    private const int PER_PAGE = 50;

    public function __construct(
        private readonly RankingCatalog $catalog,
        private readonly CriterionRankingProvider $rankingProvider,
        private readonly CriterionRepository $criterionRepository,
    ) {
    }

    public function __invoke(
        string $code,
        #[MapQueryString] RankingInput $input = new RankingInput(),
        #[MapQueryString] UniteUrbaineFilterInput $filters = new UniteUrbaineFilterInput(),
    ): Response {
        $ranking = $this->catalog->find($code);
        $criterion = null === $ranking ? null : $this->criterionRepository->findOneBy(['code' => $code]);
        if (null === $ranking || null === $criterion) {
            throw new NotFoundHttpException();
        }

        $rankFromLowest = $criterion->isRankFromLowest() !== $input->isReversed();
        $data = $this->rankingProvider->findRanking($code, $rankFromLowest, self::PER_PAGE, ($input->page - 1) * self::PER_PAGE, $filters->toFilter());

        $rows = $data['rows'];
        $siblings = array_values(array_filter(
            $this->catalog->byTheme()[$ranking->theme],
            static fn (\App\Service\Ranking\Ranking $sibling): bool => $sibling->code !== $code,
        ));

        return $this->render('ranking/show.html.twig', [
            'ranking' => $ranking,
            'criterion' => $criterion,
            'rows' => $rows,
            'period' => $data['period'],
            'total' => $data['total'],
            'nationalTotal' => $data['nationalTotal'],
            'filterQuery' => $filters->toQuery(),
            'page' => $input->page,
            'pages' => max(1, (int) ceil($data['total'] / self::PER_PAGE)),
            'reversed' => $input->isReversed(),
            'median' => $data['median'],
            'maxValue' => $data['maxValue'],
            'siblings' => $siblings,
        ]);
    }
}
