<?php

declare(strict_types=1);

namespace App\Story;

use App\Entity\CriterionValue;
use App\Entity\UniteUrbaine;
use App\Enum\CriterionValueType;
use App\Factory\CriterionFactory;
use App\Factory\PoiCategoryFactory;
use App\Factory\PoiFactory;
use App\Repository\UniteUrbaineRepository;
use Doctrine\ORM\EntityManagerInterface;
use Zenstruck\Foundry\Attribute\AsFixture;
use Zenstruck\Foundry\Story;

/**
 * Demo POI/criteria on real unités urbaines — requires `app:zones:import` to
 * have run first.
 */
#[AsFixture(name: 'main')]
final class AppStory extends Story
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UniteUrbaineRepository $uniteUrbaineRepository,
    ) {
    }

    public function build(): void
    {
        $vannes = $this->findRealUniteUrbaine('56501', 'Vannes');
        $nantes = $this->findRealUniteUrbaine('44701', 'Nantes');

        $hardwareStoreCategory = PoiCategoryFactory::new()->create([
            'code' => 'hardware_store',
            'label' => 'Grande surface de bricolage',
        ]);

        $powerPlantCategory = PoiCategoryFactory::new()->create([
            'code' => 'nuclear_power_plant',
            'label' => 'Centrale nucléaire',
        ]);

        PoiFactory::new()->create([
            'name' => 'Leroy Merlin Vannes',
            'category' => $hardwareStoreCategory,
            'brand' => 'Leroy Merlin',
            'location' => 'SRID=4326;POINT(-2.73 47.64)',
            'uniteUrbaine' => $vannes,
        ]);

        PoiFactory::new()->create([
            'name' => 'Centrale nucléaire (exemple)',
            'category' => $powerPlantCategory,
            'brand' => null,
            'location' => 'SRID=4326;POINT(-1.55 47.22)',
            'uniteUrbaine' => $nantes,
        ]);

        // No fictional "population" criterion here: `app:criteria:population:import`
        // already gives these unités urbaines real values, and Foundry's create()
        // would collide on the "population" code (it inserts, it doesn't
        // find-or-create).
        $rainyDays = CriterionFactory::new()->create([
            'code' => 'avg_annual_rainy_days',
            'label' => 'Jours de pluie (moyenne annuelle)',
            'unit' => 'jours',
            'groupLabel' => 'Climat',
            'valueType' => CriterionValueType::Decimal,
        ]);

        // CriterionValue has no persistence factory: built directly and
        // persisted through the entity manager.
        $this->entityManager->persist(new CriterionValue($rainyDays, $vannes, 111.5, 2023));
        $this->entityManager->flush();
    }

    private function findRealUniteUrbaine(string $inseeCode, string $name): UniteUrbaine
    {
        $uniteUrbaine = $this->uniteUrbaineRepository->findOneBy(['inseeCode' => $inseeCode]);
        if (null === $uniteUrbaine) {
            throw new \LogicException(\sprintf('Unité urbaine %s (%s) not found — run `bin/console app:zones:import` before loading fixtures.', $name, $inseeCode));
        }

        return $uniteUrbaine;
    }
}
