<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\UniteUrbaineRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/unites-urbaines', name: 'app_unite_urbaine_list')]
final class UniteUrbaineListController extends AbstractController
{
    public function __construct(
        private readonly UniteUrbaineRepository $uniteUrbaineRepository,
    ) {
    }

    public function __invoke(): Response
    {
        return $this->render('unite_urbaine/list.html.twig', [
            'groups' => $this->uniteUrbaineRepository->findGroupedByDepartement(),
        ]);
    }
}
