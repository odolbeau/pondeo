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

    private const string SOURCE_POPULATION = 'INSEE, populations historiques (population municipale)';

    private const string SOURCE_CENSUS = 'INSEE, recensement de la population';

    private const string SOURCE_DVF = 'DGFiP, Demandes de valeurs foncières (statistiques DVF, Etalab), ventes de 2021 à 2025';

    private const string SOURCE_RENTS = 'Estimations ANIL, à partir des données du Groupe SeLoger et de leboncoin (carte des loyers 2025) ; pondération : INSEE, recensement de la population';

    private const string TEXT_RENTS = 'Il s\'agit du loyer demandé dans les annonces de leboncoin et de SeLoger pour un logement vide, charges comprises, au 3e trimestre 2025 : un nouveau locataire paie souvent plus que ceux déjà en place. Quand une commune a trop peu d\'annonces, elle reprend le loyer estimé pour un groupe de communes voisines : plusieurs petites agglomérations peuvent alors afficher le même loyer et partager leur rang.';

    private const string SOURCE_OPEN_METEO = 'Open-Meteo.com, réanalyse ERA5, moyenne 1991-2020 (CC BY 4.0)';

    private const string SOURCE_PROJECTION = 'Open-Meteo.com, modèles climatiques CMIP6, période 2041-2050 (CC BY 4.0)';

    private const string SOURCE_RISKS = 'Géorisques (ministère de la Transition écologique, BRGM)';

    private const string TEXT_DVF = 'Le prix est une moyenne des ventes des cinq dernières années : quelques ventes exceptionnelles peuvent le tirer vers le haut. Le Bas-Rhin, le Haut-Rhin, la Moselle et Mayotte ne sont pas couverts par cette source.';

    private const string TEXT_GRID = 'La météo est calculée sur une grille de 9 à 25 km de côté, au centre de l\'agglomération : l\'effet de « bulle de chaleur » du centre-ville n\'est pas pris en compte, la réalité sera souvent un peu plus chaude qu\'ici, surtout la nuit.';

    private const string TEXT_PROJECTION = 'Ce sont des projections de modèles climatiques, pas des prévisions : une année donnée peut être bien pire ou bien meilleure, et les modèles ne sont pas toujours d\'accord entre eux. Mieux vaut comparer les villes entre elles que s\'attacher au chiffre exact.';

    /** @var array<string, Ranking> indexed by criterion code */
    private array $rankings;

    public function __construct()
    {
        $rankings = [
            new Ranking('population', 'Démographie', 'Les plus peuplées', 'Des métropoles aux villes moyennes.', new RankingNote([
                'Le nombre d\'habitants de l\'agglomération, c\'est-à-dire de toutes les communes qui forment une même zone bâtie continue, pas seulement de la commune centre.',
            ], self::SOURCE_POPULATION), scorable: false),
            new Ranking('population_croissance_annuelle_moyenne', 'Démographie', 'Les plus dynamiques', 'Où la population progresse le plus vite depuis 2006.', new RankingNote([
                'Chaque année, de combien de % la population a-t-elle augmenté en moyenne depuis 2006 ? Une valeur de 1 % signifie qu\'une agglomération de 100 000 habitants en gagne environ 1 000 chaque année ; une valeur négative, qu\'elle en perd.',
                'Une petite agglomération peut afficher un fort pourcentage pour quelques centaines d\'habitants seulement : ce classement dit la dynamique, pas le poids.',
            ], self::SOURCE_POPULATION)),
            new Ranking('densite', 'Démographie', 'Les plus denses', 'Le nombre d\'habitants au kilomètre carré.', new RankingNote([
                'Le nombre d\'habitants qui vivent, en moyenne, sur un kilomètre carré de l\'agglomération.',
                'Le calcul inclut aussi les espaces non construits (parcs, champs, forêts) des communes concernées : une grande agglomération étalée peut ainsi sembler moins dense qu\'une petite ville compacte.',
            ], self::SOURCE_POPULATION.' ; contours des communes (IGN)'), scorable: false),
            new Ranking('taux_chomage', 'Économie', 'Le moins de chômage', 'Les agglomérations où l\'on trouve le plus facilement un emploi.', new RankingNote([
                'Parmi les habitants de 15 à 64 ans qui travaillent ou cherchent un emploi, quelle part est sans emploi ? Pour 100 personnes actives, un taux de 8 % signifie que 8 cherchent du travail.',
                'Il s\'agit du chômage tel que les habitants le déclarent au recensement : il peut différer du taux officiel publié chaque trimestre et du nombre d\'inscrits à France Travail. Les chiffres ne sont disponibles que les années de recensement.',
            ], self::SOURCE_CENSUS)),
            new Ranking('taux_activite', 'Économie', 'Les plus actives', 'La part des habitants en âge de travailler qui travaillent ou cherchent un emploi.', new RankingNote([
                'Parmi les 15-64 ans, quelle part travaille ou cherche un emploi ? Les autres sont, par exemple, étudiants, au foyer ou retirés du marché du travail.',
                'Un taux élevé indique un territoire où l\'on travaille beaucoup, mais une ville étudiante affichera mécaniquement un taux plus bas sans que ce soit un mauvais signe.',
            ], self::SOURCE_CENSUS)),
            new Ranking('niveau_vie_median', 'Économie', 'Le meilleur niveau de vie', 'Le niveau de vie médian, par unité de consommation.', new RankingNote([
                'Le revenu dont dispose chaque habitant, une fois les impôts payés et les aides reçues, en tenant compte de la composition du foyer (un couple avec deux enfants n\'a pas les mêmes besoins qu\'une personne seule). C\'est un « revenu par personne » corrigé.',
                'On prend la médiane : la moitié des habitants vit avec moins que ce montant, l\'autre moitié avec plus. Contrairement à une moyenne, elle n\'est pas tirée vers le haut par quelques très hauts revenus.',
            ], 'INSEE, Filosofi (données fiscales et sociales), 2023')),
            new Ranking('taux_pauvrete', 'Économie', 'Le moins de pauvreté', 'La part des habitants sous le seuil de pauvreté.', new RankingNote([
                'La part des habitants dont le niveau de vie est inférieur à 60 % du niveau de vie médian national. Ce seuil est relatif : il se déplace avec les revenus du pays, ce n\'est pas un montant de survie.',
                'Deux villes au même niveau de vie médian peuvent avoir un taux de pauvreté très différent selon l\'écart entre leurs habitants les plus modestes et les plus aisés. Les plus petites agglomérations, dont l\'INSEE ne publie pas le chiffre pour protéger la vie privée, sont absentes.',
            ], 'INSEE, Filosofi (données fiscales et sociales), 2023')),
            new Ranking('revenu_fiscal_moyen', 'Économie', 'Les revenus fiscaux les plus élevés', 'Le revenu fiscal moyen par foyer.', new RankingNote([
                'Le revenu moyen déclaré aux impôts par chaque foyer fiscal (un couple qui déclare ensemble compte pour un), avant impôt.',
                'C\'est une moyenne : quelques très hauts revenus la tirent vers le haut, et elle révèle donc surtout les villes aisées. Pour la situation d\'un habitant « typique », le niveau de vie médian est plus parlant. Les personnes qui ne déclarent aucun revenu ne sont pas comptées.',
            ], 'DGFiP, impôt sur le revenu par commune (IRCOM)')),
            new Ranking('prix_m2', 'Immobilier', 'Le prix au m²', 'Tous biens confondus.', new RankingNote([
                'Combien coûte un mètre carré de logement à l\'achat, appartements et maisons mélangés. Pour un 60 m² à 3 000 €/m², il faut compter 180 000 €.',
                self::TEXT_DVF,
            ], self::SOURCE_DVF), invertedInScore: true, scoreTitle: 'Des prix au m² bas'),
            new Ranking('prix_m2_appartement', 'Immobilier', 'Le prix au m² des appartements', 'Pour les achats en ville.', new RankingNote([
                'Le prix moyen d\'un mètre carré d\'appartement acheté dans l\'agglomération : un bon reflet de la pression immobilière en centre-ville.',
                self::TEXT_DVF,
            ], self::SOURCE_DVF), invertedInScore: true, scoreTitle: 'Des appartements abordables'),
            new Ranking('prix_m2_maison', 'Immobilier', 'Le prix au m² des maisons', 'Pour les achats avec jardin.', new RankingNote([
                'Le prix moyen d\'un mètre carré de maison acheté dans l\'agglomération. Il porte sur la surface habitable : le terrain n\'est pas compté, mais il pèse dans le prix.',
                self::TEXT_DVF,
            ], self::SOURCE_DVF), invertedInScore: true, scoreTitle: 'Des maisons abordables'),
            new Ranking('loyer_m2_appartement', 'Immobilier', 'Le loyer au m² des appartements', 'Pour louer en ville.', new RankingNote([
                'Combien coûte par mois un mètre carré d\'appartement à la location, toutes tailles confondues. À 12 €/m², un 50 m² se loue 600 € par mois.',
                self::TEXT_RENTS,
            ], self::SOURCE_RENTS), invertedInScore: true, scoreTitle: "Des loyers d'appartement bas"),
            new Ranking('loyer_m2_maison', 'Immobilier', 'Le loyer au m² des maisons', 'Pour louer avec jardin.', new RankingNote([
                'Combien coûte par mois un mètre carré de maison à la location. Les maisons sont peu nombreuses à louer : leur loyer est plus souvent estimé sur un groupe de communes que celui des appartements.',
                self::TEXT_RENTS,
            ], self::SOURCE_RENTS), invertedInScore: true, scoreTitle: 'Des loyers de maison bas'),
            new Ranking('part_logements_vacants', 'Immobilier', 'Le moins de logements vacants', 'Un marché tendu ou une ville qui se vide ?', new RankingNote([
                'Sur 100 logements de l\'agglomération, combien sont vides, c\'est-à-dire ni habités ni utilisés comme résidence secondaire ? Les logements en vente, en location ou en attente de travaux en font partie.',
                'Peu de vacance signifie qu\'il est difficile de se loger : le marché est tendu. Beaucoup de vacance peut indiquer une ville qui perd des habitants. À l\'inverse, une station balnéaire pleine de résidences secondaires affichera peu de logements vacants sans être pour autant tendue.',
            ], self::SOURCE_CENSUS)),
            new Ranking('commerces_total_pour_1000_hab', 'Qualité de vie', 'Les mieux achalandées', 'Le nombre de commerces pour 1 000 habitants.', new RankingNote([
                'Combien de commerces (boulangeries, supermarchés, magasins de vêtements, stations-service…) pour 1 000 habitants. Avec 20 commerces pour 1 000 habitants, une ville de 50 000 habitants en compte 1 000.',
                'On compte des établissements, quelle que soit leur taille : un hypermarché pèse autant qu\'un tabac-presse. Ce classement mesure donc la densité de l\'offre de proximité plus que la puissance commerciale.',
            ], 'INSEE, Base permanente des équipements, 2025')),
            new Ranking('associations_actives_pour_1000_hab', 'Qualité de vie', 'Les plus associatives', "Le nombre d'associations actives pour 1 000 habitants.", new RankingNote([
                'Combien d\'associations (clubs de sport, troupes de théâtre, comités des fêtes, entraide…) ont fait une déclaration ces dix dernières années, pour 1 000 habitants. Avec 25 associations pour 1 000 habitants, une ville de 50 000 habitants en compte 1 250.',
                'Une association compte pour la commune de son siège, pas pour celle où elle agit : les petites villes qui servent tout un territoire arrivent souvent en tête. Le Bas-Rhin, le Haut-Rhin et la Moselle ne sont pas couverts par cette source.',
            ], 'Ministère de l\'Intérieur, Répertoire national des associations (RNA), 2026'), scorable: false),
            new Ranking('part_diplomes_superieur', 'Qualité de vie', 'Les plus diplômées', 'La part des habitants diplômés du supérieur.', new RankingNote([
                'Parmi les habitants de 15 ans ou plus qui ont terminé leurs études, quelle part a un diplôme d\'enseignement supérieur (bac+2 et plus) ?',
                'Les étudiants encore en cours d\'études ne sont pas comptés : une grande ville universitaire n\'est donc pas avantagée par ses étudiants, seulement par ceux qui y restent une fois diplômés.',
            ], self::SOURCE_CENSUS)),
            new Ranking('ensoleillement', 'Climat', 'Les plus ensoleillées', 'Les heures de soleil par an, en moyenne sur 1991-2020.', new RankingNote([
                "Le nombre d'heures par an pendant lesquelles le soleil brille, en moyenne sur trente ans (1991-2020). 2 000 heures, c'est un peu plus de cinq heures de soleil par jour en moyenne.",
                'Les valeurs viennent d\'un modèle et sont plus élevées que les mesures des stations de Météo-France : comparez les villes entre elles plutôt que ces chiffres à ceux que vous avez déjà vus ailleurs.',
            ], self::SOURCE_OPEN_METEO)),
            new Ranking('nuits_tropicales', 'Climat', 'Les nuits les plus fraîches', 'Le moins de nuits à plus de 20 °C, celles où l\'on ne récupère plus de la chaleur.', new RankingNote([
                'Le nombre de nuits par an où la température ne descend pas sous 20 °C. Ces nuits-là sont étouffantes : le corps et le logement n\'ont pas le temps de se rafraîchir, ce qui rend les journées de forte chaleur bien plus pénibles.',
                self::TEXT_GRID,
            ], self::SOURCE_OPEN_METEO)),
            new Ranking('deficit_hydrique_estival', 'Climat', 'Les moins sujettes à la sécheresse', 'L\'écart entre ce que l\'été évapore et ce qu\'il pleut.', new RankingNote([
                'En été, l\'eau qui s\'évapore du sol et des plantes dépasse en général la pluie qui tombe. Ce classement mesure cet écart sur juin, juillet et août, en millimètres : plus il est grand, plus l\'été est sec.',
                'Par exemple, 300 mm de déficit signifie qu\'il manque l\'équivalent de 30 cm d\'eau pour compenser l\'évaporation. C\'est un bon indice du risque de pelouses grillées, de restrictions d\'eau et de feux.',
                self::TEXT_GRID,
            ], self::SOURCE_OPEN_METEO)),
            new Ranking('jours_chaleur_humide', 'Climat', 'Le moins de chaleur étouffante', 'Les jours où l\'air chaud et moite empêche de se rafraîchir.', new RankingNote([
                'Une même chaleur n\'est pas vécue de la même façon selon l\'humidité : quand l\'air est moite, la sueur s\'évapore mal et le corps se refroidit mal. On compte ici les jours où la « température humide », qui combine chaleur et humidité, dépasse 22 °C.',
                self::TEXT_GRID,
            ], self::SOURCE_OPEN_METEO)),
            new Ranking('jours_plus_35_2050', 'Climat en 2050', 'Le moins de canicules en 2050', 'Les jours à plus de 35 °C par an, selon les projections 2041-2050.', new RankingNote([
                'Combien de jours par an le thermomètre dépassera 35 °C d\'ici 2050, en moyenne sur la période 2041-2050 ? Ce sont les jours de forte canicule.',
                self::TEXT_PROJECTION,
            ], self::SOURCE_PROJECTION)),
            new Ranking('nuits_tropicales_2050', 'Climat en 2050', 'Les nuits les plus fraîches en 2050', 'Les nuits à plus de 20 °C par an, selon les projections 2041-2050.', new RankingNote([
                'Combien de nuits par an la température ne descendra pas sous 20 °C, en moyenne sur 2041-2050 ? Ces nuits étouffantes sont celles qui rendent les canicules les plus dangereuses.',
                self::TEXT_PROJECTION,
            ], self::SOURCE_PROJECTION)),
            new Ranking('vagues_chaleur_2050', 'Climat en 2050', 'Le moins de vagues de chaleur en 2050', 'Les épisodes d\'au moins trois jours à plus de 30 °C, selon les projections.', new RankingNote([
                'Combien d\'épisodes de chaleur par an, en moyenne sur 2041-2050 ? Un épisode est une série d\'au moins trois jours d\'affilée à plus de 30 °C. Une série de dix jours consécutifs ne compte que pour un épisode.',
                self::TEXT_PROJECTION,
            ], self::SOURCE_PROJECTION)),
            new Ranking('hausse_temperature_2050', 'Climat en 2050', 'Le moins de réchauffement d\'ici 2050', 'La hausse de la température moyenne par rapport à 1991-2020.', new RankingNote([
                'De combien de degrés la température moyenne annuelle aura-t-elle augmenté en 2041-2050 par rapport à 1991-2020 ? Une hausse de 1,5 °C peut sembler faible, mais elle suffit à multiplier les jours de forte chaleur.',
                self::TEXT_PROJECTION,
            ], self::SOURCE_PROJECTION)),
            new Ranking('arretes_catnat', 'Risques', 'Le moins de catastrophes naturelles', 'Les arrêtés de catastrophe naturelle des vingt dernières années, par commune.', new RankingNote([
                'Quand une inondation, une sécheresse ou une tempête cause d\'importants dégâts, l\'État peut reconnaître l\'état de catastrophe naturelle d\'une commune par un arrêté. On compte ici ces arrêtés sur vingt ans, en moyenne par commune de l\'agglomération.',
                'C\'est l\'historique de ce qui s\'est déjà produit, pas une prévision. Le chiffre dépend aussi de la réactivité des communes à demander la reconnaissance.',
            ], self::SOURCE_RISKS)),
            new Ranking('part_zone_inondable', 'Risques', 'Le moins exposées aux inondations', "La part des habitants dans une commune soumise au risque d'inondation.", new RankingNote([
                'Quelle part des habitants vit dans une commune où l\'inondation est un risque officiellement recensé ? Si la seule commune concernée abrite un quart de la population, l\'agglomération affiche 25 %.',
                'Le risque est évalué pour la commune entière : il ne dit ni son ampleur, ni que tous ses habitants sont réellement concernés. Pour un logement précis, consultez la carte du risque sur Géorisques.',
            ], self::SOURCE_RISKS)),
            new Ranking('part_feux_foret', 'Risques', 'Le moins exposées aux feux de forêt', 'La part des habitants dans une commune soumise au risque de feu de forêt.', new RankingNote([
                'Quelle part des habitants vit dans une commune où le feu de forêt est un risque officiellement recensé ?',
                'Le risque est évalué pour la commune entière : il ne dit ni son ampleur, ni que tous ses habitants sont réellement concernés. Pour un logement précis, consultez la carte du risque sur Géorisques.',
            ], self::SOURCE_RISKS)),
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
