<?php

declare(strict_types=1);

namespace App\Story;

use App\Entity\Commune;
use App\Entity\ZoneCriterionValue;
use App\Enum\CriterionValueType;
use App\Factory\CriterionFactory;
use App\Factory\PoiCategoryFactory;
use App\Factory\PoiCommuneLocationFactory;
use App\Factory\PoiFactory;
use App\Repository\CommuneRepository;
use Doctrine\ORM\EntityManagerInterface;
use Zenstruck\Foundry\Attribute\AsFixture;
use Zenstruck\Foundry\Story;

/**
 * Demo POI/criteria on real communes — requires `app:zones:import` to
 * have run first.
 */
#[AsFixture(name: 'main')]
final class AppStory extends Story
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CommuneRepository $communeRepository,
    ) {
    }

    public function build(): void
    {
        $massy = $this->findRealCommune('91377', 'Massy');
        $palaiseau = $this->findRealCommune('91477', 'Palaiseau');

        $hardwareStoreCategory = PoiCategoryFactory::new()->create([
            'code' => 'hardware_store',
            'label' => 'Grande surface de bricolage',
        ]);

        $powerPlantCategory = PoiCategoryFactory::new()->create([
            'code' => 'nuclear_power_plant',
            'label' => 'Centrale nucléaire',
        ]);

        $leroyMerlinMassy = PoiFactory::new()->create([
            'name' => 'Leroy Merlin Massy',
            'category' => $hardwareStoreCategory,
            'brand' => 'Leroy Merlin',
            'location' => 'SRID=4326;POINT(2.28 48.73)',
        ]);
        PoiCommuneLocationFactory::new()->create(['poi' => $leroyMerlinMassy, 'commune' => $massy]);

        $powerPlant = PoiFactory::new()->create([
            'name' => 'Centrale nucléaire (exemple)',
            'category' => $powerPlantCategory,
            'brand' => null,
            'location' => 'SRID=4326;POINT(2.25 48.71)',
        ]);
        PoiCommuneLocationFactory::new()->create(['poi' => $powerPlant, 'commune' => $palaiseau]);

        $population = CriterionFactory::new()->create([
            'code' => 'population',
            'label' => 'Population',
            'unit' => 'hab.',
            'valueType' => CriterionValueType::Integer,
        ]);

        $rainyDays = CriterionFactory::new()->create([
            'code' => 'avg_annual_rainy_days',
            'label' => 'Jours de pluie (moyenne annuelle)',
            'unit' => 'jours',
            'valueType' => CriterionValueType::Decimal,
        ]);

        // ZoneCriterionValue has no persistence factory (its constructor is
        // private — see plan §2.6/§3): built via its own named constructor
        // and persisted directly.
        $this->entityManager->persist(ZoneCriterionValue::forZone($population, $massy, 50_000));
        $this->entityManager->persist(ZoneCriterionValue::forZone($population, $palaiseau, 33_000));
        $this->entityManager->persist(ZoneCriterionValue::forZone($rainyDays, $massy, 111.5));
        $this->entityManager->flush();
    }

    private function findRealCommune(string $inseeCode, string $name): Commune
    {
        return $this->communeRepository->findOneBy(['inseeCode' => $inseeCode])
            ?? throw new \LogicException(\sprintf('%s (%s) not found — run `bin/console app:zones:import` before loading fixtures.', $name, $inseeCode));
    }
}
