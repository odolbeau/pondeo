<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Entity\Commune;
use App\Entity\Criterion;
use App\Entity\ZoneCriterionValue;
use App\Enum\CriterionValueType;
use App\Enum\ZoneType;
use App\Repository\CommuneRepository;
use App\Repository\CriterionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class PopulationImporter
{
    private const int BATCH_SIZE = 500;

    private const string CRITERION_CODE = 'population';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private CriterionRepository $criterionRepository,
        private CommuneRepository $communeRepository,
        private CsvReader $csvReader,
    ) {
    }

    public function importPopulation(string $csvPath): int
    {
        $criterionId = $this->findOrCreatePopulationCriterion()->getId();

        /** @var array<string, Uuid> $communeIdsByCode */
        $communeIdsByCode = [];
        foreach ($this->communeRepository->findAll() as $commune) {
            $communeIdsByCode[$commune->getInseeCode()] = $commune->getId();
        }

        $this->entityManager->clear();

        $connection = $this->entityManager->getConnection();

        // Raw SQL, not entity hydration: avoids lazy-loading each existing
        // row's commune relation just to read its id (n+1 on re-imports).
        /** @var array<string, Uuid> $existingIdsByCommuneAndPeriod key: "{communeId}-{period}" */
        $existingIdsByCommuneAndPeriod = [];
        foreach ($connection->iterateAssociative(
            'SELECT id, commune_id, period FROM zone_criterion_value WHERE criterion_id = :criterionId',
            ['criterionId' => $criterionId->toRfc4122()],
        ) as $row) {
            /** @var array{id: string, commune_id: string, period: int} $row */
            $key = $row['commune_id'].'-'.$row['period'];
            $existingIdsByCommuneAndPeriod[$key] = Uuid::fromString($row['id']);
        }

        $count = 0;
        $criterion = $this->fetchCriterion($criterionId);

        foreach ($this->csvReader->read($csvPath) as $row) {
            $communeId = $communeIdsByCode[$row['code_insee']] ?? null;
            if (null === $communeId) {
                continue; // Not in métropole scope (or genuinely unmapped) — skip.
            }

            $period = (int) $row['year'];
            $population = (int) $row['population'];
            $existingId = $existingIdsByCommuneAndPeriod[$communeId->toRfc4122().'-'.$period] ?? null;

            if (null === $existingId) {
                $communeRef = $this->entityManager->getReference(Commune::class, $communeId)
                    ?? throw new \LogicException("No reference for commune id {$communeId}.");
                $this->entityManager->persist(ZoneCriterionValue::forZone($criterion, $communeRef, $population, $period));
            } else {
                $connection->executeStatement(
                    'UPDATE zone_criterion_value SET value_integer = :value WHERE id = :id',
                    ['value' => $population, 'id' => $existingId->toRfc4122()],
                );
            }

            ++$count;

            if (0 === $count % self::BATCH_SIZE) {
                $this->entityManager->flush();
                $this->entityManager->clear();
                $criterion = $this->fetchCriterion($criterionId);
            }
        }

        $this->entityManager->flush();

        return $count;
    }

    private function fetchCriterion(Uuid $id): Criterion
    {
        return $this->entityManager->getRepository(Criterion::class)->find($id)
            ?? throw new \LogicException("Population criterion {$id} vanished.");
    }

    private function findOrCreatePopulationCriterion(): Criterion
    {
        $criterion = $this->criterionRepository->findOneBy(['code' => self::CRITERION_CODE]);
        if (null !== $criterion) {
            return $criterion;
        }

        $criterion = new Criterion(self::CRITERION_CODE, 'Population', CriterionValueType::Integer);
        $criterion->setUnit('hab.');
        $criterion->setZoneType(ZoneType::Commune);

        $this->entityManager->persist($criterion);
        $this->entityManager->flush();

        return $criterion;
    }
}
