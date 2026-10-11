<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Criteria\CriterionGlossary;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/criteres', name: 'app_criteria')]
final class CriteriaListController extends AbstractController
{
    public function __construct(
        private readonly CriterionGlossary $glossary,
    ) {
    }

    public function __invoke(): Response
    {
        return $this->render('criteria/index.html.twig', ['groups' => $this->glossary->groups()]);
    }
}
