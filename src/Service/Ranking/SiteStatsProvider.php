<?php

declare(strict_types=1);

namespace App\Service\Ranking;

use Doctrine\DBAL\Connection;

/** Headline figures of the home page. */
final readonly class SiteStatsProvider
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return array{uniteUrbaines: int, criteria: int, population: int}
     */
    public function find(): array
    {
        /** @var int|numeric-string $population */
        $population = $this->connection->fetchOne(
            <<<'SQL'
                SELECT COALESCE(SUM(cv.value_integer), 0)
                FROM criterion_value cv
                INNER JOIN criterion c ON c.id = cv.criterion_id AND c.code = 'population'
                WHERE cv.period = (SELECT MAX(period) FROM criterion_value WHERE criterion_id = c.id)
                SQL,
        );

        /** @var int $uniteUrbaines */
        $uniteUrbaines = $this->connection->fetchOne('SELECT COUNT(*) FROM unite_urbaine');
        /** @var int $criteria */
        $criteria = $this->connection->fetchOne('SELECT COUNT(*) FROM criterion WHERE comparable');

        return [
            'uniteUrbaines' => $uniteUrbaines,
            'criteria' => $criteria,
            'population' => (int) $population,
        ];
    }
}
