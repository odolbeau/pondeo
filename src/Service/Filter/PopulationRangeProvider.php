<?php

declare(strict_types=1);

namespace App\Service\Filter;

use App\Repository\CriterionRepository;
use Doctrine\DBAL\Connection;

/**
 * The population the filters bound: its criterion, its latest period and the
 * smallest and largest unités urbaine at that period.
 */
final readonly class PopulationRangeProvider
{
    public function __construct(
        private Connection $connection,
        private CriterionRepository $criterionRepository,
    ) {
    }

    public function getCriterionId(): string
    {
        $criterion = $this->criterionRepository->findOneBy(['code' => 'population']);
        if (null === $criterion) {
            throw new \LogicException('Criterion "population" not found.');
        }

        return $criterion->getId()->toRfc4122();
    }

    public function getLatestPeriod(): int
    {
        $period = $this->connection->fetchOne(
            'SELECT MAX(period) FROM criterion_value WHERE criterion_id = :criterionId',
            ['criterionId' => $this->getCriterionId()],
        );

        if (!is_numeric($period)) {
            throw new \LogicException('No population data found.');
        }

        return (int) $period;
    }

    /** @return array{min: int, max: int} */
    public function getBounds(int $period): array
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT MIN(value_integer) AS min, MAX(value_integer) AS max
                FROM criterion_value
                WHERE criterion_id = :criterionId AND period = :period
                SQL,
            ['criterionId' => $this->getCriterionId(), 'period' => $period],
        );

        if (false === $row || !is_numeric($row['min']) || !is_numeric($row['max'])) {
            throw new \LogicException("No population data found at period {$period}.");
        }

        return ['min' => (int) $row['min'], 'max' => (int) $row['max']];
    }
}
