<?php

declare(strict_types=1);

namespace App\Story;

use App\Entity\ZoneCriterionValue;
use App\Enum\CriterionValueType;
use App\Enum\EpciType;
use App\Factory\CommuneFactory;
use App\Factory\CriterionFactory;
use App\Factory\DepartementFactory;
use App\Factory\EpciFactory;
use App\Factory\PoiCategoryFactory;
use App\Factory\PoiCommuneLocationFactory;
use App\Factory\PoiFactory;
use App\Factory\RegionFactory;
use Doctrine\ORM\EntityManagerInterface;
use Zenstruck\Foundry\Attribute\AsFixture;
use Zenstruck\Foundry\Story;

/**
 * Minimal dev dataset — just enough to exercise both comparison patterns
 * (POI presence/brand and nearest-POI distance) end to end, not a real
 * import (see plan §8/§9: the actual INSEE/IGN/POI import pipeline is a
 * separate future task).
 */
#[AsFixture(name: 'main')]
final class AppStory extends Story
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function build(): void
    {
        $region = RegionFactory::new()->create([
            'inseeCode' => '11',
            'name' => 'Île-de-France',
            'boundary' => 'SRID=4326;MULTIPOLYGON(((1.4 48.1, 3.6 48.1, 3.6 49.3, 1.4 49.3, 1.4 48.1)))',
        ]);

        $departement = DepartementFactory::new()->create([
            'inseeCode' => '91',
            'name' => 'Essonne',
            'region' => $region,
            'boundary' => 'SRID=4326;MULTIPOLYGON(((2.0 48.3, 2.6 48.3, 2.6 48.8, 2.0 48.8, 2.0 48.3)))',
        ]);

        $epci = EpciFactory::new()->create([
            'sirenCode' => '200084589',
            'name' => 'CA Paris-Saclay',
            'epciType' => EpciType::CommunauteDAgglomeration,
            'boundary' => 'SRID=4326;MULTIPOLYGON(((2.15 48.65, 2.35 48.65, 2.35 48.80, 2.15 48.80, 2.15 48.65)))',
        ]);

        $massy = CommuneFactory::new()->create([
            'inseeCode' => '91377',
            'name' => 'Massy',
            'departement' => $departement,
            'epci' => $epci,
            'boundary' => 'SRID=4326;MULTIPOLYGON(((2.27 48.72, 2.29 48.72, 2.29 48.74, 2.27 48.74, 2.27 48.72)))',
        ]);

        $palaiseau = CommuneFactory::new()->create([
            'inseeCode' => '91477',
            'name' => 'Palaiseau',
            'departement' => $departement,
            'epci' => $epci,
            'boundary' => 'SRID=4326;MULTIPOLYGON(((2.24 48.70, 2.26 48.70, 2.26 48.72, 2.24 48.72, 2.24 48.70)))',
        ]);

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
}
