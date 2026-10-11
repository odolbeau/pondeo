<?php

declare(strict_types=1);

namespace App\Service\Ranking;

use App\Enum\Departement;
use Doctrine\DBAL\Connection;

/**
 * Raw-SQL read side of the ranking pages: unités urbaines ordered by their
 * value of one criterion at its latest period overall, so that every row is
 * compared at the same date.
 *
 * @phpstan-type RankingRow array{rank: int, code: string, name: string, departement: ?Departement, value: int|float, population: ?int}
 */
final readonly class CriterionRankingProvider
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return array{period: ?int, total: int, rows: list<RankingRow>}
     */
    public function findRanking(string $criterionCode, bool $rankFromLowest, int $limit, int $offset = 0): array
    {
        $direction = $rankFromLowest ? 'ASC' : 'DESC';

        /** @var list<array{rank: int, code: string, name: string, departement: ?string, value: string|int|float, population: ?int, period: int, total: int}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
                WITH criterion_period AS (
                    SELECT c.id, MAX(cv.period) AS period
                    FROM criterion c
                    INNER JOIN criterion_value cv ON cv.criterion_id = c.id
                    WHERE c.code = :code AND c.comparable
                    GROUP BY c.id
                ),
                scoped AS (
                    SELECT cv.unite_urbaine_id, cp.period, COALESCE(cv.value_integer, cv.value_decimal) AS value
                    FROM criterion_value cv
                    INNER JOIN criterion_period cp ON cp.id = cv.criterion_id AND cp.period = cv.period
                    WHERE cv.value_integer IS NOT NULL OR cv.value_decimal IS NOT NULL
                ),
                ranked AS (
                    SELECT s.*, RANK() OVER (ORDER BY s.value {$direction}) AS rank, COUNT(*) OVER () AS total
                    FROM scoped s
                )
                SELECT r.rank, uu.insee_code AS code, uu.name, uu.departement, r.value, r.period, r.total,
                       (
                           SELECT p.value_integer
                           FROM criterion_value p
                           INNER JOIN criterion pc ON pc.id = p.criterion_id
                           WHERE pc.code = 'population' AND p.unite_urbaine_id = uu.id
                           ORDER BY p.period DESC
                           LIMIT 1
                       ) AS population
                FROM ranked r
                INNER JOIN unite_urbaine uu ON uu.id = r.unite_urbaine_id
                ORDER BY r.rank, uu.name
                LIMIT :limit OFFSET :offset
                SQL,
            ['code' => $criterionCode, 'limit' => $limit, 'offset' => $offset],
        );

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'rank' => $row['rank'],
                'code' => $row['code'],
                'name' => $row['name'],
                'departement' => null === $row['departement'] ? null : Departement::tryFrom($row['departement']),
                'value' => \is_string($row['value']) ? (float) $row['value'] : $row['value'],
                'population' => $row['population'],
            ];
        }

        return [
            'period' => [] === $rows ? null : $rows[0]['period'],
            'total' => [] === $rows ? 0 : $rows[0]['total'],
            'rows' => $result,
        ];
    }
}
