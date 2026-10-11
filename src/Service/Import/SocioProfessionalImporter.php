<?php

declare(strict_types=1);

namespace App\Service\Import;

/**
 * Imports the census socio-professional categories (see
 * `docs/criteria-pcs.md`): the share of each category in the population aged
 * 15 or more.
 */
final readonly class SocioProfessionalImporter
{
    public const string GROUP = 'Catégories socioprofessionnelles';

    /**
     * Share criteria, indexed by the CSV status they are computed from: code and label.
     *
     * @var array<string, array{code: string, label: string}>
     */
    private const array CATEGORIES = [
        'agriculteurs' => ['code' => 'part_agriculteurs', 'label' => 'Agriculteurs exploitants'],
        'artisans' => ['code' => 'part_artisans_commercants', 'label' => 'Artisans, commerçants, chefs d\'entreprise'],
        'cadres' => ['code' => 'part_cadres', 'label' => 'Cadres et professions intellectuelles supérieures'],
        'intermediaires' => ['code' => 'part_professions_intermediaires', 'label' => 'Professions intermédiaires'],
        'employes' => ['code' => 'part_employes', 'label' => 'Employés'],
        'ouvriers' => ['code' => 'part_ouvriers', 'label' => 'Ouvriers'],
        'retraites' => ['code' => 'part_retraites', 'label' => 'Retraités'],
        'sans_activite' => ['code' => 'part_sans_activite', 'label' => 'Autres sans activité professionnelle'],
    ];

    public function __construct(private CountRatioImporter $countRatioImporter)
    {
    }

    /**
     * @return int number of (unité urbaine, period) share values upserted
     */
    public function import(string $csvPath): int
    {
        $counts = ['population' => 'Population de 15 ans ou plus'];
        $shares = [];
        foreach (self::CATEGORIES as $status => ['code' => $code, 'label' => $label]) {
            $counts[$status] = $label;
            $shares[] = ['code' => $code, 'label' => $label, 'numerator' => $status, 'denominator' => 'population'];
        }

        return $this->countRatioImporter->import($csvPath, self::GROUP, $counts, $shares);
    }

    /**
     * The PCS codes of the census, indexed by the CSV status.
     *
     * @return array<string, string>
     */
    public static function pcsCodes(): array
    {
        return ['agriculteurs' => '1', 'artisans' => '2', 'cadres' => '3', 'intermediaires' => '4', 'employes' => '5', 'ouvriers' => '6', 'retraites' => '7', 'sans_activite' => '9'];
    }
}
