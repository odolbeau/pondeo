<?php

declare(strict_types=1);

namespace App\Service\Ranking;

use App\Enum\Departement;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Raw-SQL read side of the personal ranking: each criterion turns into a
 * percentile (1 for the best unité urbaine, 0 for the worst, at the criterion's
 * latest period overall), and a unit's score is the average of its percentiles
 * weighted by the importance given to each criterion.
 *
 * @phpstan-type ScoreRow array{rank: int, code: string, name: string, departement: ?Departement, score: float, population: ?int, strengths: list<string>}
 */
final readonly class WeightedScoreProvider
{
    /** A unit is only ranked when it has data for at least this share of the total weight. */
    private const float MIN_COVERED_WEIGHT_SHARE = 0.5;

    /** A criterion is a strength of a unit from this percentile. */
    private const float STRENGTH_PERCENTILE = 0.75;

    private const int MAX_STRENGTHS = 3;

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @param array<string, int> $weights  importance by criterion code, each above 0
     * @param list<string>       $inverted codes whose lower values are the better ones, against the criterion's own ranking
     *
     * @return array{total: int, rows: list<ScoreRow>}
     */
    public function findScores(array $weights, int $limit, int $offset = 0, array $inverted = []): array
    {
        if ([] === $weights) {
            return ['total' => 0, 'rows' => []];
        }

        [$cte, $params] = $this->scoresQuery($weights, $inverted);

        /** @var list<array{rank: int, code: string, name: string, departement: ?string, score: string|float, population: ?int, total: int}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
                {$cte},
                ranked AS (
                    SELECT s.*, RANK() OVER (ORDER BY s.score DESC) AS rank, COUNT(*) OVER () AS total
                    FROM scores s
                )
                SELECT r.rank, uu.insee_code AS code, uu.name, uu.departement, r.score, r.total,
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
            $params + ['limit' => $limit, 'offset' => $offset],
        );

        $strengths = $this->findStrengths($cte, $params, array_column($rows, 'code'));

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'rank' => $row['rank'],
                'code' => $row['code'],
                'name' => $row['name'],
                'departement' => null === $row['departement'] ? null : Departement::tryFrom($row['departement']),
                'score' => (float) $row['score'],
                'population' => $row['population'],
                'strengths' => $strengths[$row['code']] ?? [],
            ];
        }

        return ['total' => [] === $rows ? 0 : $rows[0]['total'], 'rows' => $result];
    }

    /**
     * @param array<string, mixed> $params
     * @param list<string>         $codes  INSEE codes of the units to describe
     *
     * @return array<string, list<string>> criterion codes by unit code, strongest first
     */
    private function findStrengths(string $cte, array $params, array $codes): array
    {
        if ([] === $codes) {
            return [];
        }

        /** @var list<array{unit: string, criterion: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
                {$cte}
                SELECT unit, criterion
                FROM (
                    SELECT uu.insee_code AS unit, p.code AS criterion,
                           ROW_NUMBER() OVER (PARTITION BY p.unite_urbaine_id ORDER BY p.weight * p.percentile DESC, p.code) AS position
                    FROM percentiles p
                    INNER JOIN unite_urbaine uu ON uu.id = p.unite_urbaine_id
                    WHERE uu.insee_code IN (:codes) AND p.percentile >= CAST(:strength AS double precision)
                ) strengths
                WHERE position <= CAST(:max AS integer)
                ORDER BY unit, position
                SQL,
            $params + ['codes' => $codes, 'strength' => self::STRENGTH_PERCENTILE, 'max' => self::MAX_STRENGTHS],
            ['codes' => ArrayParameterType::STRING],
        );

        $strengths = [];
        foreach ($rows as $row) {
            $strengths[$row['unit']][] = $row['criterion'];
        }

        return $strengths;
    }

    /**
     * The shared CTEs, ending on `scores` (one row per ranked unit) with the
     * per-criterion `percentiles` they come from.
     *
     * @param array<string, int> $weights
     * @param list<string>       $inverted
     *
     * @return array{string, array<string, mixed>}
     */
    private function scoresQuery(array $weights, array $inverted): array
    {
        $values = [];
        $params = ['minShare' => self::MIN_COVERED_WEIGHT_SHARE];
        $index = 0;
        foreach ($weights as $code => $weight) {
            $values[] = "(CAST(:code{$index} AS text), CAST(:weight{$index} AS integer), CAST(:inverted{$index} AS boolean))";
            $params["code{$index}"] = $code;
            $params["weight{$index}"] = $weight;
            $params["inverted{$index}"] = \in_array($code, $inverted, true) ? 'true' : 'false';
            ++$index;
        }

        $values = implode(', ', $values);

        // The best value gets the highest percentile, whichever way the criterion ranks.
        $cte = <<<SQL
            WITH weights(code, weight, inverted) AS (VALUES {$values}),
            criterion_period AS (
                SELECT c.id, c.code, (c.rank_from_lowest <> w.inverted) AS lower_is_better, w.weight, MAX(cv.period) AS period
                FROM weights w
                INNER JOIN criterion c ON c.code = w.code AND c.comparable
                INNER JOIN criterion_value cv ON cv.criterion_id = c.id
                GROUP BY c.id, w.weight, w.inverted
            ),
            scoped AS (
                SELECT cv.unite_urbaine_id, cp.code, cp.weight, cp.lower_is_better,
                       COALESCE(cv.value_integer, cv.value_decimal) AS value
                FROM criterion_value cv
                INNER JOIN criterion_period cp ON cp.id = cv.criterion_id AND cp.period = cv.period
                WHERE cv.value_integer IS NOT NULL OR cv.value_decimal IS NOT NULL
            ),
            percentiles AS (
                SELECT s.*,
                       PERCENT_RANK() OVER (PARTITION BY s.code ORDER BY CASE WHEN s.lower_is_better THEN -s.value ELSE s.value END) AS percentile
                FROM scoped s
            ),
            scores AS (
                SELECT p.unite_urbaine_id, ROUND(CAST(100 * SUM(p.weight * p.percentile) / SUM(p.weight) AS numeric), 1) AS score
                FROM percentiles p
                GROUP BY p.unite_urbaine_id
                HAVING SUM(p.weight) >= CAST(:minShare AS numeric) * (SELECT SUM(weight) FROM criterion_period)
            )
            SQL;

        return [$cte, $params];
    }
}
