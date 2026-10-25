<?php

declare(strict_types=1);

namespace App\Controller;

use App\Enum\AttractionCategory;
use App\Enum\JourneyDestination;
use App\Enum\RealEstatePriceType;
use App\Service\Filter\UniteUrbaineFilter;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Query-string filters shared by the map (and its export), the rankings and the
 * personal ranking; a future filter is a new property here. Null population
 * bounds mean "not set". The exclusions default to off: every unité urbaine is
 * kept until asked otherwise. The commerce density minimum (shops per 1000
 * inhabitants) is likewise off when null, as is the exclusion radius (km) around
 * nuclear plants. The price per m² maximum (€) has no minimum counterpart and is
 * off when null. The restaurant density minimum works like the commerce one. The
 * price maximum applies to the price type chosen (all housing by default).
 * Keeping only the unités urbaines with a railway station, or with a board game
 * association, is off by default too.
 * The train limits (minutes, by destination city) are empty, so off, by default.
 */
readonly class UniteUrbaineFilterInput
{
    public function __construct(
        #[Assert\PositiveOrZero]
        public ?int $populationMin = null,
        #[Assert\PositiveOrZero]
        public ?int $populationMax = null,
        public bool $excludeCouronne = false,
        public bool $excludePolesSecondaires = false,
        #[Assert\PositiveOrZero]
        public ?float $commerceDensityMin = null,
        #[Assert\PositiveOrZero]
        public ?float $nuclearExclusionKm = null,
        #[Assert\PositiveOrZero]
        public ?float $priceMax = null,
        public RealEstatePriceType $priceType = RealEstatePriceType::Global,
        #[Assert\PositiveOrZero]
        public ?float $restaurantDensityMin = null,
        public bool $withStation = false,
        public bool $withBoardGameAssociation = false,
        /** @var array<string, int> destination value => maximum minutes (car to the station + train) */
        #[Assert\All([new Assert\Positive()])]
        public array $trainMaxMinutes = [],
        /** Applies to the train limits above: 0 means direct trains only. */
        #[Assert\PositiveOrZero]
        public ?int $trainMaxTransfers = null,
    ) {
    }

    /**
     * @return list<AttractionCategory>
     */
    public function excludedAttractionCategories(): array
    {
        $excluded = [];
        if ($this->excludeCouronne) {
            $excluded[] = AttractionCategory::Couronne;
        }

        if ($this->excludePolesSecondaires) {
            $excluded[] = AttractionCategory::PoleSecondaire;
        }

        return $excluded;
    }

    public function toFilter(): UniteUrbaineFilter
    {
        return new UniteUrbaineFilter(
            populationMin: $this->populationMin,
            populationMax: $this->populationMax,
            excludedAttractionCategories: $this->excludedAttractionCategories(),
            commerceDensityMin: $this->commerceDensityMin,
            restaurantDensityMin: $this->restaurantDensityMin,
            nuclearExclusionKm: $this->nuclearExclusionKm,
            priceMax: $this->priceMax,
            priceCriterionCode: $this->priceType->criterionCode(),
            withStation: $this->withStation,
            withBoardGameAssociation: $this->withBoardGameAssociation,
            trainLimits: $this->trainMaxMinutes,
            trainMaxTransfers: $this->trainMaxTransfers,
        );
    }

    /**
     * The names of the query parameters, to tell whether a request carries filters.
     *
     * @return list<string>
     */
    public static function parameterNames(): array
    {
        $constructor = new \ReflectionClass(self::class)->getConstructor();
        \assert(null !== $constructor);

        return array_map(static fn (\ReflectionParameter $parameter): string => $parameter->getName(), $constructor->getParameters());
    }

    /**
     * The filters as query parameters, leaving out whatever is off, to put them
     * back in the links of a filtered page.
     *
     * @return array<string, mixed>
     */
    public function toQuery(): array
    {
        return array_filter([
            'populationMin' => $this->populationMin,
            'populationMax' => $this->populationMax,
            'excludeCouronne' => $this->excludeCouronne ? '1' : null,
            'excludePolesSecondaires' => $this->excludePolesSecondaires ? '1' : null,
            'commerceDensityMin' => $this->commerceDensityMin,
            'nuclearExclusionKm' => $this->nuclearExclusionKm,
            'priceMax' => $this->priceMax,
            'priceType' => RealEstatePriceType::Global === $this->priceType ? null : $this->priceType->value,
            'restaurantDensityMin' => $this->restaurantDensityMin,
            'withStation' => $this->withStation ? '1' : null,
            'withBoardGameAssociation' => $this->withBoardGameAssociation ? '1' : null,
            'trainMaxMinutes' => [] === $this->trainMaxMinutes ? null : $this->trainMaxMinutes,
            'trainMaxTransfers' => $this->trainMaxTransfers,
        ], static fn (mixed $value): bool => null !== $value);
    }

    #[Assert\Callback]
    public function validateTrainDestinations(ExecutionContextInterface $context): void
    {
        foreach (array_keys($this->trainMaxMinutes) as $destination) {
            if (null === JourneyDestination::tryFrom((string) $destination)) {
                $context->buildViolation('Unknown destination "{{ destination }}".')
                    ->setParameter('{{ destination }}', (string) $destination)
                    ->atPath('trainMaxMinutes')
                    ->addViolation();
            }
        }
    }
}
