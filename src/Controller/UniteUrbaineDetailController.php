<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\UniteUrbaine;
use App\Service\Detail\AgePyramid;
use App\Service\Detail\CriterionDetail;
use App\Service\Detail\CriterionGroupDetail;
use App\Service\Detail\UniteUrbaineCriteriaProvider;
use App\Service\Detail\UniteUrbainePoiProvider;
use App\Service\Import\AgeStructureImporter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Full page of an unité urbaine. The same response also feeds the map's side
 * panel: Turbo extracts the `unite-urbaine-detail` frame out of it.
 */
#[Route('/unites-urbaines/{code}', name: 'app_unite_urbaine_detail', requirements: ['code' => '[0-9A-Za-z]{5}'])]
final class UniteUrbaineDetailController extends AbstractController
{
    public function __construct(
        private readonly UniteUrbaineCriteriaProvider $criteriaProvider,
        private readonly UniteUrbainePoiProvider $poiProvider,
    ) {
    }

    public function __invoke(
        Request $request,
        #[MapEntity(mapping: ['code' => 'inseeCode'])]
        UniteUrbaine $uniteUrbaine,
    ): Response {
        $groups = $this->criteriaProvider->findGroups($uniteUrbaine);
        $inFrame = $request->headers->has('Turbo-Frame');

        return $this->render('unite_urbaine/detail.html.twig', [
            'uniteUrbaine' => $uniteUrbaine,
            // The age shares only repeat the pyramid in the narrow map panel; the full page keeps them, with their rank and evolution.
            'groups' => $inFrame ? array_values(array_filter($groups, static fn (CriterionGroupDetail $group): bool => AgeStructureImporter::GROUP !== $group->label)) : $groups,
            'poiCategories' => $this->poiProvider->findCategories($uniteUrbaine),
            'population' => $this->findPopulation($groups),
            'agePyramid' => null === $uniteUrbaine->getAgePyramid() ? null : AgePyramid::fromStored($uniteUrbaine->getAgePyramid()),
            'inFrame' => $inFrame,
        ]);
    }

    /**
     * @param list<CriterionGroupDetail> $groups
     */
    private function findPopulation(array $groups): ?CriterionDetail
    {
        foreach ($groups as $group) {
            foreach ($group->criteria as $criterion) {
                if ('population' === $criterion->code) {
                    return $criterion;
                }
            }
        }

        return null;
    }
}
