<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/mentions-legales', name: 'app_legal_notice')]
final class LegalNoticeController extends AbstractController
{
    public function __invoke(): Response
    {
        return $this->render('legal_notice/index.html.twig');
    }
}
