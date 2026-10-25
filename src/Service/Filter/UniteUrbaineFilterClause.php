<?php

declare(strict_types=1);

namespace App\Service\Filter;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;

/**
 * The FROM and WHERE keeping the unités urbaine of a filter, to embed in a
 * query. `uu` is the unité urbaine and `cv` its population at the latest
 * period; every parameter is prefixed with `f`, so the clause can sit in a
 * query with parameters of its own.
 */
final readonly class UniteUrbaineFilterClause
{
    /**
     * @param array<string, mixed>                            $params
     * @param array<string, ArrayParameterType|ParameterType> $types
     */
    public function __construct(
        public string $sql,
        public array $params,
        public array $types,
    ) {
    }
}
