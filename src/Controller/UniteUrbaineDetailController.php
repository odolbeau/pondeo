<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\UniteUrbaine;
use App\Enum\DetailSection;
use App\Service\Criteria\CriterionGlossary;
use App\Service\Detail\AgePyramid;
use App\Service\Detail\CriterionDetail;
use App\Service\Detail\CriterionGroupDetail;
use App\Service\Detail\DetailCard;
use App\Service\Detail\PoiCategoryDetail;
use App\Service\Detail\StationAccessDetail;
use App\Service\Detail\UniteUrbaineCriteriaProvider;
use App\Service\Detail\UniteUrbainePoiProvider;
use App\Service\Detail\UniteUrbaineStationAccessProvider;
use App\Service\Import\AgeStructureImporter;
use App\Service\Ranking\RankingCatalog;
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
    private const int COLUMNS = 3;

    public function __construct(
        private readonly UniteUrbaineCriteriaProvider $criteriaProvider,
        private readonly UniteUrbainePoiProvider $poiProvider,
        private readonly UniteUrbaineStationAccessProvider $stationAccessProvider,
        private readonly RankingCatalog $rankingCatalog,
        private readonly CriterionGlossary $glossary,
    ) {
    }

    public function __invoke(
        Request $request,
        #[MapEntity(mapping: ['code' => 'inseeCode'])]
        UniteUrbaine $uniteUrbaine,
    ): Response {
        $groups = $this->criteriaProvider->findGroups($uniteUrbaine);
        $inFrame = $request->headers->has('Turbo-Frame');
        $agePyramids = $this->findAgePyramids($uniteUrbaine, $inFrame);

        // The age shares repeat the pyramid: in the narrow map panel they are dropped, on the full page they move under the pyramid.
        $ageGroup = null;
        $displayedGroups = [];
        foreach ($groups as $group) {
            if (AgeStructureImporter::GROUP !== $group->label) {
                $displayedGroups[] = $group;
            } elseif (!$inFrame && [] !== $agePyramids) {
                $ageGroup = $group;
            } elseif (!$inFrame) {
                $displayedGroups[] = $group;
            }
        }

        $poiCategories = $this->poiProvider->findCategories($uniteUrbaine);
        $stations = $this->stationAccessProvider->find($uniteUrbaine);

        return $this->render('unite_urbaine/detail.html.twig', [
            'uniteUrbaine' => $uniteUrbaine,
            'sections' => $this->toSections($displayedGroups, $ageGroup, $agePyramids, $stations, $poiCategories, $inFrame ? 1 : self::COLUMNS),
            'ageGroup' => $ageGroup,
            'poiCategories' => $poiCategories,
            'stations' => $stations,
            'population' => $this->findPopulation($groups),
            'agePyramids' => $agePyramids,
            'inFrame' => $inFrame,
            'rankingCodes' => $this->rankingCatalog->codes(),
            'glossary' => $this->describeCriteria($groups),
        ]);
    }

    /**
     * @param list<CriterionGroupDetail> $groups
     * @param array<int, AgePyramid>     $agePyramids
     * @param list<StationAccessDetail>  $stations
     * @param list<PoiCategoryDetail>    $poiCategories
     *
     * @return list<array{section: DetailSection, columns: list<list<DetailCard>>}>
     */
    private function toSections(array $groups, ?CriterionGroupDetail $ageGroup, array $agePyramids, array $stations, array $poiCategories, int $columnCount): array
    {
        $cardsBySection = [];
        foreach ($groups as $group) {
            $weight = 2.0 + \count($group->criteria) * 1.4 + (null === $group->distribution ? 0.0 : 8.0);
            $cardsBySection[DetailSection::fromGroup($group->label)->value][] = new DetailCard(DetailCard::GROUP, $weight, $group);
        }

        if ([] !== $agePyramids) {
            $weight = 14.0 + (null === $ageGroup ? 0.0 : 3.0 + \count($ageGroup->criteria) * 1.4 + (null === $ageGroup->distribution ? 0.0 : 8.0));
            $cardsBySection[DetailSection::Population->value] = [new DetailCard(DetailCard::PYRAMID, $weight), ...($cardsBySection[DetailSection::Population->value] ?? [])];
        }

        if ([] !== $stations) {
            $cardsBySection[DetailSection::CadreDeVie->value][] = new DetailCard(DetailCard::STATIONS, 2.0 + array_sum(array_map(static fn (StationAccessDetail $station): float => 1.2 + \count($station->journeys) * 0.8, $stations)));
        }

        if ([] !== $poiCategories) {
            $weight = 2.0 + array_sum(array_map(static fn (PoiCategoryDetail $category): float => 2.0 + \count($category->pois) * 1.6, $poiCategories));
            $cardsBySection[DetailSection::CadreDeVie->value][] = new DetailCard(DetailCard::POIS, $weight);
        }

        $sections = [];
        foreach (DetailSection::cases() as $section) {
            if (isset($cardsBySection[$section->value])) {
                $sections[] = ['section' => $section, 'columns' => DetailCard::distribute($cardsBySection[$section->value], $columnCount)];
            }
        }

        return $sections;
    }

    /**
     * The pyramid by bracket width: the full page offers both, the narrow map panel only the coarser one.
     *
     * @return array<int, AgePyramid>
     */
    private function findAgePyramids(UniteUrbaine $uniteUrbaine, bool $inFrame): array
    {
        $stored = $uniteUrbaine->getAgePyramid();
        if (null === $stored) {
            return [];
        }

        $widths = $inFrame ? [10] : [5, 10];

        return array_combine($widths, array_map(static fn (int $width): AgePyramid => AgePyramid::fromStored($stored, $width), $widths));
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

    /**
     * @param list<CriterionGroupDetail> $groups
     *
     * @return array<string, array{description: ?string, source: ?string}> indexed by criterion code
     */
    private function describeCriteria(array $groups): array
    {
        $glossary = [];
        foreach ($groups as $group) {
            foreach ($group->criteria as $criterion) {
                $glossary[$criterion->code] = $this->glossary->describe($criterion->code, $group->label);
            }
        }

        return $glossary;
    }
}
