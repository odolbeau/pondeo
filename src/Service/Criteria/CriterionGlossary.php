<?php

declare(strict_types=1);

namespace App\Service\Criteria;

use App\Entity\Criterion;
use App\Repository\CriterionRepository;
use App\Service\Ranking\RankingCatalog;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Plain-language description of every criterion, grouped as on the detail
 * page. The texts live here, keyed by criterion code, because the criteria are
 * created by the importers from the vendored data.
 */
final readonly class CriterionGlossary
{
    /** Groups in display order; any other group comes after, alphabetically. */
    private const array GROUP_ORDER = [
        'Démographie', 'Âge', 'Catégories socioprofessionnelles', 'Diplômes',
        'Revenus', 'Revenus fiscaux', 'Emploi',
        'Logement', 'Immobilier', 'Commerces', 'Vie associative',
        'Climat', 'Climat 2050', 'Risques',
    ];

    private const string OTHER_GROUP = 'Autres';

    private const string CENSUS = 'INSEE, recensement de la population';

    private const string FILOSOFI = 'INSEE, Filosofi (données fiscales et sociales), 2023';

    /** Source of each group; a criterion can override it in SOURCE_OVERRIDES. */
    private const array GROUP_SOURCES = [
        'Démographie' => 'INSEE, populations historiques (population municipale)',
        'Âge' => self::CENSUS,
        'Catégories socioprofessionnelles' => self::CENSUS,
        'Diplômes' => self::CENSUS,
        'Revenus' => self::FILOSOFI,
        'Revenus fiscaux' => 'DGFiP, impôt sur le revenu par commune (IRCOM)',
        'Emploi' => self::CENSUS,
        'Logement' => self::CENSUS,
        'Immobilier' => 'DGFiP, Demandes de valeurs foncières (statistiques DVF, Etalab), ventes de 2021 à 2025',
        'Commerces' => 'INSEE, Base permanente des équipements, 2025',
        'Vie associative' => 'Ministère de l\'Intérieur, Répertoire national des associations (RNA), 2026',
        'Climat' => 'Open-Meteo.com, réanalyse ERA5, moyenne 1991-2020 (CC BY 4.0)',
        'Climat 2050' => 'Open-Meteo.com, modèles climatiques CMIP6, période 2041-2050 (CC BY 4.0)',
        'Risques' => 'Géorisques (ministère de la Transition écologique, BRGM)',
    ];

    private const array SOURCE_OVERRIDES = [
        'densite' => 'INSEE, populations historiques (population municipale) ; contours des communes (IGN)',
        'tendance_temperature' => 'Open-Meteo.com, réanalyse ERA5, 1991-2020 (CC BY 4.0)',
        'zone_sismique' => 'Géorisques, zonage sismique réglementaire',
    ];

    private const array DESCRIPTIONS = [
        'population' => "Le nombre d'habitants de l'agglomération, c'est-à-dire de toutes les communes qui forment une même zone bâtie continue (ce que l'INSEE appelle une unité urbaine), et pas seulement de la commune centre.\n\nC'est ce périmètre qui est comparé partout sur le site : Lyon compte ainsi pour l'ensemble de son agglomération, pas pour la seule commune de Lyon. Le périmètre est figé à la définition actuelle pour que les années restent comparables.",
        'population_croissance_annuelle_moyenne' => "De combien de % la population augmente chaque année en moyenne depuis 2006. Une valeur de 1 % signifie qu'une agglomération de 100 000 habitants en gagne environ 1 000 par an ; une valeur négative, qu'elle en perd.\n\nLe pourcentage dit la dynamique, pas le poids : une petite ville peut afficher un fort taux pour quelques centaines d'habitants seulement.",
        'densite' => "Le nombre d'habitants par km² de l'agglomération.\n\nLe calcul inclut les espaces non construits (parcs, champs, forêts) des communes concernées : une grande agglomération étalée, avec beaucoup de zones pavillonnaires ou de terres agricoles, peut ainsi paraître moins dense qu'une petite ville compacte. C'est une densité moyenne, qui ne dit rien de la densité du centre-ville.",

        'part_moins_15_ans' => 'La part des habitants de moins de 15 ans : enfants et très jeunes adolescents.',
        'part_15_24_ans' => 'La part des habitants de 15 à 24 ans : lycéens, étudiants et jeunes actifs. Elle est élevée dans les villes universitaires.',
        'part_25_39_ans' => 'La part des habitants de 25 à 39 ans, l\'âge des débuts de carrière et des jeunes familles.',
        'part_40_54_ans' => 'La part des habitants de 40 à 54 ans, l\'âge de la pleine activité.',
        'part_55_64_ans' => 'La part des habitants de 55 à 64 ans, en fin de carrière ou proches de la retraite.',
        'part_65_79_ans' => 'La part des habitants de 65 à 79 ans : les jeunes retraités.',
        'part_80_ans_et_plus' => 'La part des habitants de 80 ans ou plus, ceux qui ont le plus besoin de soins et de services de proximité.',

        'part_agriculteurs' => 'La part des habitants actifs ou retraités ayant été agriculteurs exploitants. Elle est faible en ville et plus forte dans les agglomérations rurales.',
        'part_artisans_commercants' => 'La part des artisans, commerçants et chefs d\'entreprise parmi les habitants de 15 ans ou plus.',
        'part_cadres' => 'La part des cadres et professions intellectuelles supérieures (ingénieurs, professeurs, médecins, cadres d\'entreprise…).',
        'part_professions_intermediaires' => 'La part des professions intermédiaires : techniciens, infirmiers, instituteurs, contremaîtres…',
        'part_employes' => 'La part des employés : personnels administratifs, de commerce, de service ou de santé.',
        'part_ouvriers' => 'La part des ouvriers, qualifiés ou non, de l\'industrie, de l\'artisanat ou de l\'agriculture.',
        'part_retraites' => 'La part des retraités parmi les habitants de 15 ans ou plus.',
        'part_sans_activite' => 'La part des habitants sans activité professionnelle qui ne sont pas retraités : étudiants, personnes au foyer, chômeurs n\'ayant jamais travaillé…',

        'part_diplomes_superieur' => 'Parmi les habitants de 15 ans ou plus qui ont fini leurs études, la part ayant un diplôme du supérieur (bac+2 et plus). Les étudiants en cours d\'études ne sont pas comptés.',

        'niveau_vie_median' => "Le revenu dont dispose chaque habitant une fois les impôts payés et les aides reçues, corrigé de la composition du foyer : un couple avec deux enfants n'a pas les mêmes besoins qu'une personne seule, on compte donc 1 part pour le premier adulte, 0,5 pour chaque autre adulte et 0,3 par enfant.\n\nOn prend la médiane : la moitié des habitants vit avec moins que ce montant, l'autre moitié avec plus. Contrairement à une moyenne, elle n'est pas tirée vers le haut par quelques très hauts revenus, et décrit mieux la situation d'un habitant « typique ».",
        'taux_pauvrete' => "La part des habitants dont le niveau de vie est inférieur à 60 % de la médiane nationale. Le seuil est relatif : il évolue avec les revenus du pays, ce n'est pas un montant de survie.\n\nDeux agglomérations au même niveau de vie médian peuvent avoir un taux de pauvreté très différent, selon l'écart entre leurs habitants les plus modestes et les plus aisés. L'INSEE ne publie pas ce taux pour les plus petites agglomérations, par protection de la vie privée : elles n'ont pas de valeur ici.",
        'revenu_fiscal_moyen' => "Le revenu moyen déclaré aux impôts par foyer fiscal, avant impôt. Un foyer fiscal est ce qui figure sur une déclaration : un couple qui déclare ensemble compte pour un.\n\nC'est une moyenne : quelques très hauts revenus suffisent à la tirer vers le haut, ce qui en fait un bon révélateur des villes aisées. Pour la situation d'un habitant typique, le niveau de vie médian est plus parlant. Les personnes qui ne déclarent aucun revenu ne sont pas comptées.",
        'part_foyers_moins_15000' => "La part des foyers fiscaux déclarant moins de 15 000 € par an : les revenus les plus modestes.\n\nLa répartition par tranche n'est publiée que pour les communes qui comptent assez de foyers (les plus grandes). Pour une agglomération composée d'une grande commune et de petites, la répartition affichée est donc surtout celle de la grande.",
        'part_foyers_15000_30000' => 'La part des foyers fiscaux déclarant entre 15 000 et 30 000 € par an.',
        'part_foyers_30000_50000' => 'La part des foyers fiscaux déclarant entre 30 000 et 50 000 € par an, le cœur des classes moyennes.',
        'part_foyers_50000_100000' => 'La part des foyers fiscaux déclarant entre 50 000 et 100 000 € par an : les foyers aisés.',
        'part_foyers_plus_100000' => 'La part des foyers fiscaux déclarant plus de 100 000 € par an : les foyers les plus riches.',

        'taux_chomage' => "Parmi les 15-64 ans qui travaillent ou cherchent un emploi, la part qui est sans emploi. Pour 100 personnes actives, un taux de 8 % signifie que 8 cherchent du travail.\n\nIl s'agit du chômage déclaré lors du recensement, ce qui le distingue du taux officiel publié chaque trimestre et du nombre d'inscrits à France Travail : les valeurs ne sont pas directement comparables avec celles que vous avez pu voir ailleurs, mais restent comparables d'une agglomération à l'autre. Il n'existe que pour les années de recensement (2012, 2017, 2023).",
        'taux_activite' => 'La part des 15-64 ans qui travaillent ou cherchent un emploi. Les autres sont par exemple étudiants ou au foyer.',

        'part_proprietaires' => 'La part des résidences principales occupées par leur propriétaire, par opposition aux locataires.',
        'part_logement_social' => 'La part des résidences principales louées dans le parc social (HLM).',
        'part_logements_vacants' => "La part des logements vides, c'est-à-dire ni habités ni utilisés comme résidence secondaire. Y figurent les logements en vente, en location ou en attente de travaux.\n\nPeu de vacance signale un marché tendu où il est difficile de se loger ; beaucoup de vacance peut indiquer une ville qui perd des habitants. À l'inverse, une station balnéaire pleine de résidences secondaires affiche peu de logements vacants sans être pour autant tendue.",

        'prix_m2' => "Le prix moyen d'un mètre carré de logement acheté, appartements et maisons confondus, d'après les ventes de 2021 à 2025. Pour un 60 m² à 3 000 €/m², il faut compter 180 000 €.\n\nLe prix est rapporté à la surface habitable : pour une maison, le terrain n'est pas compté dans les mètres carrés mais pèse bien dans le prix, ce qui peut gonfler le prix au m² des maisons avec un grand jardin. C'est une moyenne, plus sensible aux ventes exceptionnelles qu'une médiane.\n\nAttention, certains territoires n'ont pas de valeur : le Bas-Rhin, le Haut-Rhin et la Moselle (Alsace-Moselle) relèvent d'un autre système de registre foncier, le livre foncier, qui n'est pas dans la base des ventes utilisée ici. C'est aussi le cas de Mayotte. Paris, Lyon et Marseille sont regroupés au niveau de la commune, sans distinguer les arrondissements.",
        'prix_m2_appartement' => "Le prix moyen d'un mètre carré d'appartement acheté, d'après les ventes de 2021 à 2025 : un bon reflet de la pression immobilière en centre-ville.\n\nC'est une moyenne, plus sensible aux ventes exceptionnelles qu'une médiane. Les agglomérations d'Alsace-Moselle (Bas-Rhin, Haut-Rhin, Moselle) et de Mayotte n'ont pas de valeur : leurs ventes ne figurent pas dans la source, qui s'appuie sur un registre différent du leur.",
        'prix_m2_maison' => "Le prix moyen d'un mètre carré de maison acheté, d'après les ventes de 2021 à 2025.\n\nLe prix est rapporté à la surface habitable : le terrain n'est pas compté dans les mètres carrés, mais il pèse dans le prix. Une maison avec un grand jardin ressort donc plus chère au m² qu'une maison identique sur une petite parcelle, et l'écart avec le prix des appartements mesure en partie le prix de l'espace. Les agglomérations d'Alsace-Moselle (Bas-Rhin, Haut-Rhin, Moselle) et de Mayotte n'ont pas de valeur : leurs ventes ne figurent pas dans la source.",

        'commerces_total_pour_1000_hab' => "Le nombre total de commerces pour 1 000 habitants. Avec 20 commerces pour 1 000 habitants, une ville de 50 000 habitants en compte 1 000.\n\nOn compte des établissements, quelle que soit leur taille : un hypermarché pèse autant qu'un tabac-presse. Les stations-service sont incluses, pas les bornes de recharge. Ce critère mesure donc la densité de l'offre de proximité plus que la puissance commerciale. La population utilisée est celle du recensement le plus récent, légèrement antérieur au décompte des commerces.",
        'commerces_alimentaires_pour_1000_hab' => 'Le nombre de commerces alimentaires (boulangeries, boucheries, supérettes, marchés…) pour 1 000 habitants.',
        'commerces_non_alimentaires_pour_1000_hab' => 'Le nombre de commerces non alimentaires (vêtements, librairies, bricolage, stations-service…) pour 1 000 habitants.',
        'commerces_grandes_surfaces_pour_1000_hab' => 'Le nombre d\'hypermarchés, supermarchés et grandes surfaces de bricolage pour 1 000 habitants.',
        'commerces_total' => 'Le nombre total de commerces de l\'agglomération. Ce chiffre suit la taille de la ville : il sert à calculer la densité pour 1 000 habitants.',
        'commerces_alimentaires' => 'Le nombre de commerces alimentaires de l\'agglomération, avant rapport à la population.',
        'commerces_non_alimentaires' => 'Le nombre de commerces non alimentaires de l\'agglomération, avant rapport à la population.',
        'commerces_grandes_surfaces' => 'Le nombre de grandes surfaces de l\'agglomération, avant rapport à la population.',

        'associations_actives_pour_1000_hab' => "Le nombre d'associations actives pour 1 000 habitants. Avec 25 associations pour 1 000 habitants, une ville de 50 000 habitants en compte 1 250.\n\nUne association est active si elle a fait une déclaration (création ou modification) au cours des dix dernières années : le répertoire ne sait pas qu'une association a cessé d'exister tant que personne ne le déclare, et ce filtre écarte une bonne partie des associations dormantes. Sur le territoire, une association compte pour la commune de son siège, pas pour celle où elle agit : les petites villes-centres, dont les associations servent tout le bassin alentour, ressortent donc souvent en tête. Le Bas-Rhin, le Haut-Rhin et la Moselle ne sont pas couverts (associations de droit local).",
        'associations_total_pour_1000_hab' => 'Le nombre de toutes les associations déclarées pour 1 000 habitants, y compris celles qui ne font plus rien. Comparé aux associations actives, il montre la part du stock qui a cessé de se déclarer.',
        'associations_sport_pour_1000_hab' => "Le nombre d'associations sportives et de plein air (clubs, chasse, pêche…) actives pour 1 000 habitants.",
        'associations_culture_pour_1000_hab' => "Le nombre d'associations culturelles et de patrimoine (musique, théâtre, danse, arts, sauvegarde du patrimoine…) actives pour 1 000 habitants.",
        'associations_loisirs_pour_1000_hab' => "Le nombre d'associations de loisirs, d'amicales et de vie de quartier (clubs, comités des fêtes, centres socioculturels, anciens élèves…) actives pour 1 000 habitants.",
        'associations_solidarite_pour_1000_hab' => "Le nombre d'associations de solidarité et d'action sociale (aide caritative, humanitaire, services aux familles et aux personnes âgées…) actives pour 1 000 habitants.",
        'associations_sante_pour_1000_hab' => "Le nombre d'associations de santé et du médico-social actives pour 1 000 habitants.",
        'associations_education_pour_1000_hab' => "Le nombre d'associations d'éducation, de formation et de recherche (parents d'élèves, soutien scolaire…) actives pour 1 000 habitants.",
        'associations_environnement_pour_1000_hab' => "Le nombre d'associations d'environnement et de cadre de vie (protection de la nature, quartiers, usagers…) actives pour 1 000 habitants.",
        'associations_total' => 'Le nombre de toutes les associations déclarées de l\'agglomération. Ce chiffre suit la taille de la ville : il sert à calculer la densité pour 1 000 habitants.',
        'associations_actives' => 'Le nombre d\'associations actives de l\'agglomération, avant rapport à la population.',
        'associations_sport' => 'Le nombre d\'associations de cette famille dans l\'agglomération, avant rapport à la population.',
        'associations_culture' => 'Le nombre d\'associations de cette famille dans l\'agglomération, avant rapport à la population.',
        'associations_loisirs' => 'Le nombre d\'associations de cette famille dans l\'agglomération, avant rapport à la population.',
        'associations_solidarite' => 'Le nombre d\'associations de cette famille dans l\'agglomération, avant rapport à la population.',
        'associations_sante' => 'Le nombre d\'associations de cette famille dans l\'agglomération, avant rapport à la population.',
        'associations_education' => 'Le nombre d\'associations de cette famille dans l\'agglomération, avant rapport à la population.',
        'associations_environnement' => 'Le nombre d\'associations de cette famille dans l\'agglomération, avant rapport à la population.',

        'temperature_moyenne' => 'La température moyenne sur l\'année, en moyenne sur 1991-2020. Elle décrit un climat sans dire s\'il est meilleur ou moins bon.',
        'jours_plus_30' => 'Le nombre de jours par an où le thermomètre dépasse 30 °C : les journées vraiment chaudes.',
        'jours_plus_35' => 'Le nombre de jours par an où le thermomètre dépasse 35 °C : les journées de forte canicule.',
        'nuits_tropicales' => "Le nombre de nuits par an où la température ne descend pas sous 20 °C. Ces nuits étouffantes rendent la chaleur bien plus pénible : le corps et le logement n'ont pas le temps de se rafraîchir, ce qui est un facteur de risque majeur lors des canicules.\n\nLa météo est calculée sur une grille de 9 à 25 km de côté, au centre de l'agglomération : l'effet de « bulle de chaleur » du centre-ville n'est pas pris en compte, et la réalité sera souvent un peu plus chaude qu'ici, surtout la nuit.",
        'jours_gel' => 'Le nombre de jours par an où la température descend sous 0 °C.',
        'amplitude_thermique' => 'L\'écart entre le mois le plus chaud et le mois le plus froid : une faible valeur indique un climat doux toute l\'année, une forte valeur des hivers froids et des étés chauds.',
        'precipitations' => 'La quantité de pluie et de neige fondue tombée en un an. 1 mm équivaut à 1 litre d\'eau par m².',
        'jours_pluie' => 'Le nombre de jours par an où il tombe au moins 1 mm de pluie.',
        'ensoleillement' => "Le nombre d'heures par an pendant lesquelles le soleil brille, en moyenne sur trente ans (1991-2020). 2 000 heures, c'est un peu plus de cinq heures de soleil par jour en moyenne.\n\nLes valeurs viennent d'une réanalyse météorologique, un modèle qui reconstitue le temps à partir de mesures, et sont plus élevées que celles des stations de Météo-France. Comparez les agglomérations entre elles plutôt que ces chiffres à ceux que vous avez déjà vus ailleurs.",
        'jours_neige' => 'Le nombre de jours par an où il tombe au moins 1 cm de neige.',
        'deficit_hydrique_estival' => "En été, l'eau qui s'évapore du sol et des plantes dépasse en général la pluie qui tombe. Ce critère mesure cet écart sur juin, juillet et août, en millimètres : plus il est grand, plus l'été est sec.\n\nPar exemple, 300 mm de déficit signifie qu'il manque l'équivalent de 30 cm d'eau pour compenser l'évaporation. C'est un bon indice du risque de pelouses grillées, de restrictions d'eau et de feux.",
        'jours_vent_fort' => 'Le nombre de jours par an avec des rafales d\'au moins 60 km/h. Le modèle lisse les rafales : les extrêmes locaux sont sous-estimés.',
        'vagues_chaleur' => 'Le nombre d\'épisodes par an d\'au moins trois jours d\'affilée à plus de 30 °C. Une série de dix jours ne compte que pour un épisode.',
        'serie_seche_max' => 'La plus longue période de jours consécutifs sans pluie notable (moins de 1 mm), la plus longue de chaque année puis moyennée.',
        'jours_pluie_forte' => 'Le nombre de jours par an où il tombe au moins 20 mm de pluie, soit 20 litres par m² : les grosses averses.',
        'tendance_temperature' => 'De combien la température moyenne a augmenté par décennie entre 1991 et 2020 : le réchauffement déjà observé sur place.',
        'jours_chaleur_humide' => "Une même chaleur n'est pas vécue de la même façon selon l'humidité : quand l'air est moite, la sueur s'évapore mal et le corps se refroidit mal. On compte ici les jours où la « température humide », qui combine chaleur et humidité, dépasse 22 °C.\n\nLe seuil de 22 °C a été choisi parce qu'il départage bien les agglomérations : plus haut, beaucoup n'auraient aucun jour ; plus bas, presque toutes en auraient.",
        'temperature_ressentie_max' => 'La température ressentie la plus élevée de l\'année, en tenant compte de l\'humidité et du vent, en moyenne sur 30 ans.',
        'deficit_pression_vapeur' => 'À quel point l\'air est sec en été : plus la valeur est élevée, plus l\'air assèche sols et végétation, avec un risque accru de sécheresse.',
        'humidite_sol_ete' => 'La quantité d\'eau contenue dans les premiers centimètres du sol en été. Un sol humide signifie des étés moins secs. Valeur issue d\'un modèle, pas d\'une mesure.',

        'jours_plus_35_2050' => "Le nombre de jours par an à plus de 35 °C attendu vers 2050, en moyenne sur 2041-2050.\n\nCe sont des projections de modèles climatiques, pas des prévisions : une année donnée peut être bien pire ou bien meilleure, et les modèles ne sont pas toujours d'accord entre eux, surtout sur les extrêmes. Mieux vaut comparer les agglomérations entre elles que s'attacher au chiffre exact.",
        'nuits_tropicales_2050' => 'Le nombre de nuits par an à plus de 20 °C attendu vers 2050 (moyenne 2041-2050).',
        'hausse_temperature_2050' => "De combien de degrés la température moyenne aura augmenté vers 2050 (moyenne 2041-2050) par rapport à 1991-2020. Une hausse de 1,5 °C peut sembler faible, mais elle suffit à multiplier les jours de forte chaleur.\n\nLes deux périodes viennent des mêmes modèles, ce qui annule en grande partie leurs biais. Ce sont des projections, pas des prévisions : comparez les agglomérations entre elles plutôt que de retenir le chiffre exact.",
        'vagues_chaleur_2050' => 'Le nombre d\'épisodes de chaleur par an (au moins trois jours à plus de 30 °C) attendu vers 2050.',
        'variation_precipitations_2050' => 'De combien de % le cumul annuel de pluie change vers 2050. Plus ou moins de pluie n\'est pas en soi meilleur ou pire : ce critère n\'est pas classé.',
        'incertitude_hausse_2050' => "L'écart entre les modèles climatiques sur l'ampleur du réchauffement vers 2050 : la hausse la plus forte moins la plus faible, en degrés.\n\nUne valeur élevée signifie que les modèles divergent, pas que le risque est plus fort : une agglomération peut être certaine de connaître un fort réchauffement. Ce critère dit à quel point on peut se fier à la projection.",

        'arretes_catnat' => "Quand une inondation, une sécheresse ou une tempête cause d'importants dégâts, l'État peut reconnaître l'état de catastrophe naturelle d'une commune par un arrêté. On compte ici ces arrêtés sur vingt ans, par commune en moyenne.\n\nC'est l'historique de ce qui s'est déjà produit, pas une prévision. Le chiffre dépend aussi de la réactivité des communes à demander la reconnaissance, et une commune qui a subi un seul événement important peut cumuler plusieurs arrêtés (inondation, puis sécheresse).",
        'part_zone_inondable' => "La part des habitants vivant dans une commune où l'inondation est un risque officiellement recensé. Si la seule commune concernée abrite un quart de la population, l'agglomération affiche 25 %.\n\nLe risque est évalué pour la commune entière : il ne dit ni son ampleur, ni que tous ses habitants sont réellement concernés. Pour un logement précis, consultez la carte du risque sur Géorisques.",
        'argiles' => "Certains sols argileux gonflent quand ils sont humides et se rétractent par temps sec. Ces mouvements peuvent fissurer les fondations des maisons, un sinistre de plus en plus fréquent avec les sécheresses. L'exposition va de 1 (faible) à 3 (forte).\n\nLa valeur est mesurée en un seul point par commune puis moyennée sur l'agglomération : une commune dont le sol varie beaucoup est donc représentée par un seul endroit, et les zones non cartographiées n'entrent pas dans la moyenne.",
        'part_feux_foret' => 'La part des habitants vivant dans une commune où le feu de forêt est un risque recensé.',
        'zone_sismique' => 'Le zonage réglementaire du risque de séisme, de 1 (très faible) à 5 (fort). Pour une agglomération, c\'est la zone la plus élevée de ses communes.',
        'part_radon_fort' => "Le radon est un gaz naturel d'origine souterraine, présent surtout dans les régions granitiques, qui peut s'accumuler dans les logements mal aérés et augmente le risque de cancer du poumon à long terme. Les communes sont classées en trois niveaux de potentiel.\n\nOn compte ici la part des habitants des communes du niveau le plus fort. Cela ne signifie pas que chaque logement est touché : il s'agit d'un classement réglementaire par commune, qui se vérifie par un test dans le logement.",
    ];

    public function __construct(
        private CriterionRepository $criterionRepository,
        private RankingCatalog $rankingCatalog,
    ) {
    }

    /**
     * @return array{description: ?string, source: ?string}
     */
    public function describe(string $code, ?string $group): array
    {
        return [
            'description' => self::DESCRIPTIONS[$code] ?? null,
            'source' => self::SOURCE_OVERRIDES[$code] ?? (null === $group ? null : (self::GROUP_SOURCES[$group] ?? null)),
        ];
    }

    /**
     * @return list<array{label: string, slug: string, criteria: list<array{criterion: Criterion, description: ?string, source: ?string, ranked: bool}>}>
     */
    public function groups(): array
    {
        $byGroup = [];
        foreach ($this->criterionRepository->findBy([], ['position' => 'ASC', 'label' => 'ASC']) as $criterion) {
            $group = $criterion->getGroupLabel();
            $byGroup[$group ?? self::OTHER_GROUP][] = [
                'criterion' => $criterion,
                'description' => $this->describe($criterion->getCode(), $group)['description'] ?? $criterion->getDescription(),
                'source' => $this->describe($criterion->getCode(), $group)['source'],
                'ranked' => null !== $this->rankingCatalog->find($criterion->getCode()),
            ];
        }

        uksort($byGroup, static fn (string $a, string $b): int => self::rank($a) <=> self::rank($b) ?: strcmp($a, $b));

        $slugger = new AsciiSlugger('fr');
        $groups = [];
        foreach ($byGroup as $label => $criteria) {
            $groups[] = ['label' => $label, 'slug' => $slugger->slug($label)->lower()->toString(), 'criteria' => $criteria];
        }

        return $groups;
    }

    private static function rank(string $group): int
    {
        if (self::OTHER_GROUP === $group) {
            return \PHP_INT_MAX;
        }

        $index = array_search($group, self::GROUP_ORDER, true);

        return false === $index ? \PHP_INT_MAX - 1 : $index;
    }
}
