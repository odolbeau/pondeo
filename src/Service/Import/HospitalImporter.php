<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Entity\Poi;
use App\Entity\PoiCategory;
use App\Enum\HospitalType;
use App\Repository\PoiCategoryRepository;
use App\Repository\PoiRepository;
use App\Service\PoiUniteUrbaineResolver;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reads the vendored `resources/geo/hopitaux.csv` (see
 * HospitalSourceDownloader) and upserts its establishments as POIs, one
 * category per hospital type under the "hospital" parent category, and one
 * POI more in the "hospital_emergency" category for those with an emergency
 * department. Both are matched on the establishment's FINESS number.
 * Idempotent: re-running updates in place, never duplicates, moves an
 * establishment whose type changed to its new category, and drops the ones the
 * file no longer lists. Needs the zones imported first, to attach each POI to
 * its unité urbaine.
 */
final readonly class HospitalImporter
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PoiCategoryRepository $poiCategoryRepository,
        private PoiRepository $poiRepository,
        private PoiUniteUrbaineResolver $uniteUrbaineResolver,
        private CsvReader $csvReader,
    ) {
    }

    /**
     * @return array{hospitals: int, emergencies: int} number of POIs imported
     */
    public function import(string $csvPath): array
    {
        $hospitalCategories = $this->findOrCreateHospitalCategories();
        $emergencyCategory = $this->findOrCreate(HospitalType::EMERGENCY_CATEGORY_CODE, HospitalType::EMERGENCY_LABEL, null);
        $this->entityManager->flush();

        $staleHospitals = $this->indexByExternalId(array_values($hospitalCategories['byType']));
        $staleEmergencies = $this->indexByExternalId([$emergencyCategory]);

        $hospitals = 0;
        $emergencies = 0;
        foreach ($this->csvReader->read($csvPath) as $row) {
            // EWKT is longitude first.
            $location = \sprintf('SRID=4326;POINT(%.7F %.7F)', (float) $row['lon'], (float) $row['lat']);
            $finess = $row['finess'];

            $this->upsert($staleHospitals[$finess] ?? null, $row['name'], $hospitalCategories['byType'][HospitalType::from($row['type'])->value], $location, $finess, $row['address'], null);
            unset($staleHospitals[$finess]);
            ++$hospitals;

            if ('1' === $row['emergency']) {
                $this->upsert($staleEmergencies[$finess] ?? null, $row['name'], $emergencyCategory, $location, $finess, $row['address'], '1' === $row['emergency_pediatric'] ? ['pediatric' => true] : null);
                unset($staleEmergencies[$finess]);
                ++$emergencies;
            }
        }

        // Both are keyed by FINESS number: list them first, or one would overwrite the other.
        foreach ([...array_values($staleHospitals), ...array_values($staleEmergencies)] as $stalePoi) {
            $this->entityManager->remove($stalePoi);
        }

        $this->entityManager->flush();
        $this->entityManager->clear();

        return ['hospitals' => $hospitals, 'emergencies' => $emergencies];
    }

    /**
     * @param array<string, mixed>|null $attributes
     */
    private function upsert(?Poi $poi, string $name, PoiCategory $category, string $location, string $finess, string $address, ?array $attributes): void
    {
        if (null === $poi) {
            $poi = new Poi($name, $category, $location);
            $poi->setExternalId($finess);
            $this->entityManager->persist($poi);
        } else {
            $poi->setName($name);
            $poi->setCategory($category);
            $poi->setLocation($location);
        }

        $poi->setAddress('' === $address ? null : $address);
        $poi->setAttributes($attributes);

        $this->uniteUrbaineResolver->resolve($poi);
    }

    /**
     * @param list<PoiCategory> $categories
     *
     * @return array<string, Poi>
     */
    private function indexByExternalId(array $categories): array
    {
        $pois = [];
        foreach ($categories as $category) {
            foreach ($this->poiRepository->findByCategoryWithUniteUrbaine($category) as $poi) {
                $pois[(string) $poi->getExternalId()] = $poi;
            }
        }

        return $pois;
    }

    /**
     * @return array{parent: PoiCategory, byType: array<string, PoiCategory>}
     */
    private function findOrCreateHospitalCategories(): array
    {
        $parent = $this->findOrCreate(HospitalType::PARENT_CATEGORY_CODE, HospitalType::PARENT_LABEL, null);

        $byType = [];
        foreach (HospitalType::cases() as $type) {
            $byType[$type->value] = $this->findOrCreate($type->categoryCode(), $type->label(), $parent);
        }

        return ['parent' => $parent, 'byType' => $byType];
    }

    private function findOrCreate(string $code, string $label, ?PoiCategory $parent): PoiCategory
    {
        $category = $this->poiCategoryRepository->findOneBy(['code' => $code]);
        if (null === $category) {
            $category = new PoiCategory($code, $label);
            $this->entityManager->persist($category);
        }

        $category->setLabel($label);
        $category->setParent($parent);

        return $category;
    }
}
