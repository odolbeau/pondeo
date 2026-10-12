<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\UniteUrbaineRepository;
use App\Service\Detail\UniteUrbaineComparisonProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/comparer', name: 'app_compare')]
final class UniteUrbaineCompareController extends AbstractController
{
    public function __construct(
        private readonly UniteUrbaineRepository $uniteUrbaineRepository,
        private readonly UniteUrbaineComparisonProvider $comparisonProvider,
    ) {
    }

    public function __invoke(#[MapQueryString] CompareInput $input = new CompareInput()): Response
    {
        $codes = $input->codes();
        $unites = $this->uniteUrbaineRepository->findByCodes($codes);
        if (\count($unites) !== \count($codes)) {
            throw new NotFoundHttpException('Unknown unité urbaine.');
        }

        return $this->render('unite_urbaine/compare.html.twig', [
            'unites' => $unites,
            'groups' => \count($unites) < 2 ? [] : $this->comparisonProvider->compare($unites),
            'maxCities' => CompareInput::MAX_CITIES,
        ]);
    }
}
