<?php

declare(strict_types=1);

namespace App\Service\Detail;

final readonly class CriterionGroupDetail
{
    /**
     * @param list<CriterionDetail> $criteria
     */
    public function __construct(
        public string $label,
        public array $criteria,
    ) {
    }
}
