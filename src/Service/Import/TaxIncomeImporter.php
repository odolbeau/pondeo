<?php

declare(strict_types=1);

namespace App\Service\Import;

/**
 * Imports the DGFiP income tax figures by commune (see
 * `docs/criteria-tax-income.md`): the mean reference taxable income of a tax
 * household, and the share of households in each bracket of income.
 */
final readonly class TaxIncomeImporter
{
    public const string GROUP = 'Revenus fiscaux';

    public function __construct(private CountRatioImporter $countRatioImporter)
    {
    }

    /**
     * @return int number of (unité urbaine, period) values upserted
     */
    public function import(string $csvPath): int
    {
        return $this->countRatioImporter->import(
            $csvPath,
            self::GROUP,
            [
                'foyers' => 'Foyers fiscaux',
                'foyers_tranches' => 'Foyers fiscaux des communes ventilées par tranche',
                'rfr' => 'Revenu fiscal de référence (milliers d\'euros)',
                'moins_15k' => 'Foyers à moins de 15 000 €',
                '15_30k' => 'Foyers de 15 000 à 30 000 €',
                '30_50k' => 'Foyers de 30 000 à 50 000 €',
                '50_100k' => 'Foyers de 50 000 à 100 000 €',
                'plus_100k' => 'Foyers à plus de 100 000 €',
            ],
            [
                ['code' => 'revenu_fiscal_moyen', 'label' => 'Revenu fiscal moyen par foyer', 'numerator' => 'rfr', 'denominator' => 'foyers', 'unit' => '€', 'factor' => 1000],
                ['code' => 'part_foyers_moins_15000', 'label' => 'Foyers à moins de 15 000 €', 'numerator' => 'moins_15k', 'denominator' => 'foyers_tranches', 'rankFromLowest' => true],
                ['code' => 'part_foyers_15000_30000', 'label' => 'Foyers de 15 000 à 30 000 €', 'numerator' => '15_30k', 'denominator' => 'foyers_tranches'],
                ['code' => 'part_foyers_30000_50000', 'label' => 'Foyers de 30 000 à 50 000 €', 'numerator' => '30_50k', 'denominator' => 'foyers_tranches'],
                ['code' => 'part_foyers_50000_100000', 'label' => 'Foyers de 50 000 à 100 000 €', 'numerator' => '50_100k', 'denominator' => 'foyers_tranches'],
                ['code' => 'part_foyers_plus_100000', 'label' => 'Foyers à plus de 100 000 €', 'numerator' => 'plus_100k', 'denominator' => 'foyers_tranches'],
            ],
        );
    }
}
