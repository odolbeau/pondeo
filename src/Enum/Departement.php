<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The 96 départements of France métropolitaine (INSEE code, `2A`/`2B` for Corse). An enum
 * rather than a table, for the same reason as {@see Region}.
 */
enum Departement: string
{
    case Ain = '01';
    case Aisne = '02';
    case Allier = '03';
    case AlpesDeHauteProvence = '04';
    case HautesAlpes = '05';
    case AlpesMaritimes = '06';
    case Ardeche = '07';
    case Ardennes = '08';
    case Ariege = '09';
    case Aube = '10';
    case Aude = '11';
    case Aveyron = '12';
    case BouchesDuRhone = '13';
    case Calvados = '14';
    case Cantal = '15';
    case Charente = '16';
    case CharenteMaritime = '17';
    case Cher = '18';
    case Correze = '19';
    case CoteDOr = '21';
    case CotesDArmor = '22';
    case Creuse = '23';
    case Dordogne = '24';
    case Doubs = '25';
    case Drome = '26';
    case Eure = '27';
    case EureEtLoir = '28';
    case Finistere = '29';
    case CorseDuSud = '2A';
    case HauteCorse = '2B';
    case Gard = '30';
    case HauteGaronne = '31';
    case Gers = '32';
    case Gironde = '33';
    case Herault = '34';
    case IlleEtVilaine = '35';
    case Indre = '36';
    case IndreEtLoire = '37';
    case Isere = '38';
    case Jura = '39';
    case Landes = '40';
    case LoirEtCher = '41';
    case Loire = '42';
    case HauteLoire = '43';
    case LoireAtlantique = '44';
    case Loiret = '45';
    case Lot = '46';
    case LotEtGaronne = '47';
    case Lozere = '48';
    case MaineEtLoire = '49';
    case Manche = '50';
    case Marne = '51';
    case HauteMarne = '52';
    case Mayenne = '53';
    case MeurtheEtMoselle = '54';
    case Meuse = '55';
    case Morbihan = '56';
    case Moselle = '57';
    case Nievre = '58';
    case Nord = '59';
    case Oise = '60';
    case Orne = '61';
    case PasDeCalais = '62';
    case PuyDeDome = '63';
    case PyreneesAtlantiques = '64';
    case HautesPyrenees = '65';
    case PyreneesOrientales = '66';
    case BasRhin = '67';
    case HautRhin = '68';
    case Rhone = '69';
    case HauteSaone = '70';
    case SaoneEtLoire = '71';
    case Sarthe = '72';
    case Savoie = '73';
    case HauteSavoie = '74';
    case Paris = '75';
    case SeineMaritime = '76';
    case SeineEtMarne = '77';
    case Yvelines = '78';
    case DeuxSevres = '79';
    case Somme = '80';
    case Tarn = '81';
    case TarnEtGaronne = '82';
    case Var = '83';
    case Vaucluse = '84';
    case Vendee = '85';
    case Vienne = '86';
    case HauteVienne = '87';
    case Vosges = '88';
    case Yonne = '89';
    case TerritoireDeBelfort = '90';
    case Essonne = '91';
    case HautsDeSeine = '92';
    case SeineSaintDenis = '93';
    case ValDeMarne = '94';
    case ValDOise = '95';

    public function label(): string
    {
        return match ($this) {
            self::Ain => 'Ain',
            self::Aisne => 'Aisne',
            self::Allier => 'Allier',
            self::AlpesDeHauteProvence => 'Alpes-de-Haute-Provence',
            self::HautesAlpes => 'Hautes-Alpes',
            self::AlpesMaritimes => 'Alpes-Maritimes',
            self::Ardeche => 'Ardèche',
            self::Ardennes => 'Ardennes',
            self::Ariege => 'Ariège',
            self::Aube => 'Aube',
            self::Aude => 'Aude',
            self::Aveyron => 'Aveyron',
            self::BouchesDuRhone => 'Bouches-du-Rhône',
            self::Calvados => 'Calvados',
            self::Cantal => 'Cantal',
            self::Charente => 'Charente',
            self::CharenteMaritime => 'Charente-Maritime',
            self::Cher => 'Cher',
            self::Correze => 'Corrèze',
            self::CoteDOr => 'Côte-d\'Or',
            self::CotesDArmor => 'Côtes-d\'Armor',
            self::Creuse => 'Creuse',
            self::Dordogne => 'Dordogne',
            self::Doubs => 'Doubs',
            self::Drome => 'Drôme',
            self::Eure => 'Eure',
            self::EureEtLoir => 'Eure-et-Loir',
            self::Finistere => 'Finistère',
            self::CorseDuSud => 'Corse-du-Sud',
            self::HauteCorse => 'Haute-Corse',
            self::Gard => 'Gard',
            self::HauteGaronne => 'Haute-Garonne',
            self::Gers => 'Gers',
            self::Gironde => 'Gironde',
            self::Herault => 'Hérault',
            self::IlleEtVilaine => 'Ille-et-Vilaine',
            self::Indre => 'Indre',
            self::IndreEtLoire => 'Indre-et-Loire',
            self::Isere => 'Isère',
            self::Jura => 'Jura',
            self::Landes => 'Landes',
            self::LoirEtCher => 'Loir-et-Cher',
            self::Loire => 'Loire',
            self::HauteLoire => 'Haute-Loire',
            self::LoireAtlantique => 'Loire-Atlantique',
            self::Loiret => 'Loiret',
            self::Lot => 'Lot',
            self::LotEtGaronne => 'Lot-et-Garonne',
            self::Lozere => 'Lozère',
            self::MaineEtLoire => 'Maine-et-Loire',
            self::Manche => 'Manche',
            self::Marne => 'Marne',
            self::HauteMarne => 'Haute-Marne',
            self::Mayenne => 'Mayenne',
            self::MeurtheEtMoselle => 'Meurthe-et-Moselle',
            self::Meuse => 'Meuse',
            self::Morbihan => 'Morbihan',
            self::Moselle => 'Moselle',
            self::Nievre => 'Nièvre',
            self::Nord => 'Nord',
            self::Oise => 'Oise',
            self::Orne => 'Orne',
            self::PasDeCalais => 'Pas-de-Calais',
            self::PuyDeDome => 'Puy-de-Dôme',
            self::PyreneesAtlantiques => 'Pyrénées-Atlantiques',
            self::HautesPyrenees => 'Hautes-Pyrénées',
            self::PyreneesOrientales => 'Pyrénées-Orientales',
            self::BasRhin => 'Bas-Rhin',
            self::HautRhin => 'Haut-Rhin',
            self::Rhone => 'Rhône',
            self::HauteSaone => 'Haute-Saône',
            self::SaoneEtLoire => 'Saône-et-Loire',
            self::Sarthe => 'Sarthe',
            self::Savoie => 'Savoie',
            self::HauteSavoie => 'Haute-Savoie',
            self::Paris => 'Paris',
            self::SeineMaritime => 'Seine-Maritime',
            self::SeineEtMarne => 'Seine-et-Marne',
            self::Yvelines => 'Yvelines',
            self::DeuxSevres => 'Deux-Sèvres',
            self::Somme => 'Somme',
            self::Tarn => 'Tarn',
            self::TarnEtGaronne => 'Tarn-et-Garonne',
            self::Var => 'Var',
            self::Vaucluse => 'Vaucluse',
            self::Vendee => 'Vendée',
            self::Vienne => 'Vienne',
            self::HauteVienne => 'Haute-Vienne',
            self::Vosges => 'Vosges',
            self::Yonne => 'Yonne',
            self::TerritoireDeBelfort => 'Territoire de Belfort',
            self::Essonne => 'Essonne',
            self::HautsDeSeine => 'Hauts-de-Seine',
            self::SeineSaintDenis => 'Seine-Saint-Denis',
            self::ValDeMarne => 'Val-de-Marne',
            self::ValDOise => "Val-d'Oise",
        };
    }

    public function region(): Region
    {
        return match ($this) {
            self::Ain,
            self::Allier,
            self::Ardeche,
            self::Cantal,
            self::Drome,
            self::Isere,
            self::Loire,
            self::HauteLoire,
            self::PuyDeDome,
            self::Rhone,
            self::Savoie,
            self::HauteSavoie => Region::AuvergneRhoneAlpes,
            self::CoteDOr,
            self::Doubs,
            self::Jura,
            self::Nievre,
            self::HauteSaone,
            self::SaoneEtLoire,
            self::Yonne,
            self::TerritoireDeBelfort => Region::BourgogneFrancheComte,
            self::CotesDArmor,
            self::Finistere,
            self::IlleEtVilaine,
            self::Morbihan => Region::Bretagne,
            self::Cher,
            self::EureEtLoir,
            self::Indre,
            self::IndreEtLoire,
            self::LoirEtCher,
            self::Loiret => Region::CentreValDeLoire,
            self::CorseDuSud,
            self::HauteCorse => Region::Corse,
            self::Ardennes,
            self::Aube,
            self::Marne,
            self::HauteMarne,
            self::MeurtheEtMoselle,
            self::Meuse,
            self::Moselle,
            self::BasRhin,
            self::HautRhin,
            self::Vosges => Region::GrandEst,
            self::Aisne,
            self::Nord,
            self::Oise,
            self::PasDeCalais,
            self::Somme => Region::HautsDeFrance,
            self::Paris,
            self::SeineEtMarne,
            self::Yvelines,
            self::Essonne,
            self::HautsDeSeine,
            self::SeineSaintDenis,
            self::ValDeMarne,
            self::ValDOise => Region::IleDeFrance,
            self::Calvados,
            self::Eure,
            self::Manche,
            self::Orne,
            self::SeineMaritime => Region::Normandie,
            self::Charente,
            self::CharenteMaritime,
            self::Correze,
            self::Creuse,
            self::Dordogne,
            self::Gironde,
            self::Landes,
            self::LotEtGaronne,
            self::PyreneesAtlantiques,
            self::DeuxSevres,
            self::Vienne,
            self::HauteVienne => Region::NouvelleAquitaine,
            self::Ariege,
            self::Aude,
            self::Aveyron,
            self::Gard,
            self::HauteGaronne,
            self::Gers,
            self::Herault,
            self::Lot,
            self::Lozere,
            self::HautesPyrenees,
            self::PyreneesOrientales,
            self::Tarn,
            self::TarnEtGaronne => Region::Occitanie,
            self::LoireAtlantique,
            self::MaineEtLoire,
            self::Mayenne,
            self::Sarthe,
            self::Vendee => Region::PaysDeLaLoire,
            self::AlpesDeHauteProvence,
            self::HautesAlpes,
            self::AlpesMaritimes,
            self::BouchesDuRhone,
            self::Var,
            self::Vaucluse => Region::ProvenceAlpesCoteDAzur,
        };
    }
}
