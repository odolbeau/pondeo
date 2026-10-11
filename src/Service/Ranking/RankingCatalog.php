<?php

declare(strict_types=1);

namespace App\Service\Ranking;

/**
 * The criteria worth a ranking page: those where "first" or "last" means
 * something. Age brackets, socio-professional categories and the like are
 * left out, as are the non-comparable raw counts.
 */
final class RankingCatalog
{
    /** Rankings shown on the home page. */
    public const array FEATURED = ['population', 'niveau_vie_median', 'prix_m2', 'taux_chomage'];

    /** @var array<string, Ranking> indexed by criterion code */
    private array $rankings;

    public function __construct()
    {
        $rankings = [
            new Ranking('population', 'Démographie', 'Les plus peuplées', 'Des métropoles aux villes moyennes.'),
            new Ranking('population_croissance_annuelle_moyenne', 'Démographie', 'Les plus dynamiques', 'Où la population progresse le plus vite depuis 2006.'),
            new Ranking('densite', 'Démographie', 'Les plus denses', 'Le nombre d\'habitants au kilomètre carré.'),
            new Ranking('taux_chomage', 'Économie', 'Le moins de chômage', 'Les agglomérations où l\'on trouve le plus facilement un emploi.'),
            new Ranking('taux_activite', 'Économie', 'Les plus actives', 'La part des habitants en âge de travailler qui travaillent ou cherchent un emploi.'),
            new Ranking('niveau_vie_median', 'Économie', 'Le meilleur niveau de vie', 'Le niveau de vie médian, par unité de consommation.'),
            new Ranking('taux_pauvrete', 'Économie', 'Le moins de pauvreté', 'La part des habitants sous le seuil de pauvreté.'),
            new Ranking('revenu_fiscal_moyen', 'Économie', 'Les revenus fiscaux les plus élevés', 'Le revenu fiscal moyen par foyer.'),
            new Ranking('prix_m2', 'Immobilier', 'Le prix au m²', 'Tous biens confondus.'),
            new Ranking('prix_m2_appartement', 'Immobilier', 'Le prix au m² des appartements', 'Pour les achats en ville.'),
            new Ranking('prix_m2_maison', 'Immobilier', 'Le prix au m² des maisons', 'Pour les achats avec jardin.'),
            new Ranking('part_logements_vacants', 'Immobilier', 'Le moins de logements vacants', 'Un marché tendu ou une ville qui se vide ?'),
            new Ranking('commerces_total_pour_1000_hab', 'Qualité de vie', 'Les mieux achalandées', 'Le nombre de commerces pour 1 000 habitants.'),
            new Ranking('part_diplomes_superieur', 'Qualité de vie', 'Les plus diplômées', 'La part des habitants diplômés du supérieur.'),
        ];

        foreach ($rankings as $ranking) {
            $this->rankings[$ranking->code] = $ranking;
        }
    }

    public function find(string $code): ?Ranking
    {
        return $this->rankings[$code] ?? null;
    }

    /** @return list<Ranking> */
    public function all(): array
    {
        return array_values($this->rankings);
    }

    /** @return array<string, list<Ranking>> indexed by theme, in catalog order */
    public function byTheme(): array
    {
        $themes = [];
        foreach ($this->rankings as $ranking) {
            $themes[$ranking->theme][] = $ranking;
        }

        return $themes;
    }

    /** @return list<Ranking> */
    public function featured(): array
    {
        return array_map(fn (string $code): Ranking => $this->rankings[$code], self::FEATURED);
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_keys($this->rankings);
    }
}
