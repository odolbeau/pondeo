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
        public ?BracketDistribution $distribution = null,
    ) {
    }

    /**
     * The period every criterion of the group shares, shown once next to the
     * group title; null when they differ, each row then shows its own.
     */
    public function commonPeriod(): ?int
    {
        $periods = array_unique(array_map(static fn (CriterionDetail $criterion): int => $criterion->period, $this->criteria));

        return 1 === \count($periods) ? array_first($periods) : null;
    }
}
