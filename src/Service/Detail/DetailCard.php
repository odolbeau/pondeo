<?php

declare(strict_types=1);

namespace App\Service\Detail;

/**
 * A card of the detail page, with a rough height used to balance the columns.
 */
final readonly class DetailCard
{
    public const string GROUP = 'group';

    public const string PYRAMID = 'pyramid';

    public const string STATIONS = 'stations';

    public const string POIS = 'pois';

    public const string SHOPS = 'shops';

    public function __construct(
        public string $type,
        public float $weight,
        public ?CriterionGroupDetail $group = null,
    ) {
    }

    /**
     * Deals the cards, in order, each to the currently shortest column.
     *
     * @param list<self> $cards
     *
     * @return list<list<self>>
     */
    public static function distribute(array $cards, int $columnCount): array
    {
        $columns = array_fill(0, $columnCount, []);
        $heights = array_fill(0, $columnCount, 0.0);

        foreach ($cards as $card) {
            $shortest = 0;
            foreach ($heights as $index => $height) {
                if ($height < $heights[$shortest]) {
                    $shortest = $index;
                }
            }

            $columns[$shortest][] = $card;
            $heights[$shortest] += $card->weight;
        }

        return $columns;
    }
}
