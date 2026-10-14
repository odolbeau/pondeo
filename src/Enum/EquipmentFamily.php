<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * A family of facility types counted together as one criterion: its value is
 * the code of the count criterion, which the density per 1000 inhabitants is
 * derived from (see `docs/equipements-bpe.md`). The types are BPE codes of the
 * vendored facility file.
 */
enum EquipmentFamily: string
{
    public const string GROUP_SHOPS = 'Commerces';

    public const string GROUP_HEALTH = 'Santé';

    public const string GROUP_CARE = 'Petite enfance et seniors';

    public const string GROUP_SPORT_CULTURE = 'Sport et culture';

    public const string GROUP_SERVICES = 'Services et tourisme';

    case ShopsTotal = 'commerces_total';
    case ShopsLarge = 'commerces_grandes_surfaces';
    case ShopsFood = 'commerces_alimentaires';
    case ShopsNonFood = 'commerces_non_alimentaires';
    case GeneralPractitioners = 'medecins_generalistes';
    case Specialists = 'medecins_specialistes';
    case Dentists = 'dentistes';
    case Paramedics = 'paramedicaux';
    case Pharmacies = 'pharmacies';
    case Hospitals = 'etablissements_sante';
    case Nurseries = 'creches';
    case ElderlyHomes = 'hebergement_personnes_agees';
    case SportsFacilities = 'equipements_sportifs';
    case CulturalFacilities = 'equipements_culturels';
    case Restaurants = 'restaurants';
    case EverydayServices = 'services_quotidien';
    case TouristAccommodation = 'hebergement_touristique';

    /** Label of the count criterion. */
    public function label(): string
    {
        return match ($this) {
            self::ShopsTotal => 'Nombre de commerces',
            self::ShopsLarge => 'Nombre de grandes surfaces',
            self::ShopsFood => 'Nombre de commerces alimentaires',
            self::ShopsNonFood => 'Nombre de commerces non alimentaires',
            self::GeneralPractitioners => 'Nombre de médecins généralistes',
            self::Specialists => 'Nombre de médecins spécialistes',
            self::Dentists => 'Nombre de chirurgiens-dentistes',
            self::Paramedics => 'Nombre de kinésithérapeutes, podologues et sages-femmes',
            self::Pharmacies => 'Nombre de pharmacies',
            self::Hospitals => 'Nombre d\'établissements de soins',
            self::Nurseries => 'Nombre de crèches',
            self::ElderlyHomes => 'Nombre d\'hébergements pour personnes âgées',
            self::SportsFacilities => 'Nombre d\'équipements sportifs',
            self::CulturalFacilities => 'Nombre d\'équipements culturels',
            self::Restaurants => 'Nombre de restaurants',
            self::EverydayServices => 'Nombre de services du quotidien',
            self::TouristAccommodation => 'Nombre d\'hébergements touristiques',
        };
    }

    /** Label of the density criterion, which the detail page shows with the count in its title; the group title already names the theme for the shops. */
    public function densityLabel(): string
    {
        return match ($this) {
            self::ShopsTotal => 'Total',
            self::ShopsLarge => 'Grandes surfaces',
            self::ShopsFood => 'Alimentaires',
            self::ShopsNonFood => 'Non alimentaires',
            self::GeneralPractitioners => 'Médecins généralistes',
            self::Specialists => 'Médecins spécialistes',
            self::Dentists => 'Chirurgiens-dentistes',
            self::Paramedics => 'Kinés, podologues, sages-femmes',
            self::Pharmacies => 'Pharmacies',
            self::Hospitals => 'Établissements de soins',
            self::Nurseries => 'Crèches',
            self::ElderlyHomes => 'Hébergement des personnes âgées',
            self::SportsFacilities => 'Équipements sportifs',
            self::CulturalFacilities => 'Cinémas, bibliothèques, conservatoires',
            self::Restaurants => 'Restaurants',
            self::EverydayServices => 'Banques, poste, coiffure, beauté, pressing',
            self::TouristAccommodation => 'Hôtels et campings',
        };
    }

    /** What the count counts, as the count criterion's unit. */
    public function unit(): string
    {
        return match ($this) {
            self::ShopsTotal, self::ShopsLarge, self::ShopsFood, self::ShopsNonFood => 'commerces',
            self::GeneralPractitioners, self::Specialists, self::Dentists, self::Paramedics => 'professionnels',
            default => 'établissements',
        };
    }

    public function group(): string
    {
        return match ($this) {
            self::ShopsTotal, self::ShopsLarge, self::ShopsFood, self::ShopsNonFood => self::GROUP_SHOPS,
            self::GeneralPractitioners, self::Specialists, self::Dentists, self::Paramedics, self::Pharmacies, self::Hospitals => self::GROUP_HEALTH,
            self::Nurseries, self::ElderlyHomes => self::GROUP_CARE,
            self::SportsFacilities, self::CulturalFacilities => self::GROUP_SPORT_CULTURE,
            self::Restaurants, self::EverydayServices, self::TouristAccommodation => self::GROUP_SERVICES,
        };
    }

    /** Rank among the criteria of the group on the detail page. */
    public function position(): int
    {
        return match ($this) {
            self::ShopsTotal => 0,
            self::ShopsFood => 1,
            self::ShopsNonFood => 2,
            self::ShopsLarge => 3,
            self::GeneralPractitioners => 0,
            self::Specialists => 1,
            self::Dentists => 2,
            self::Paramedics => 3,
            self::Pharmacies => 4,
            self::Hospitals => 5,
            self::Nurseries => 0,
            self::ElderlyHomes => 1,
            self::SportsFacilities => 0,
            self::CulturalFacilities => 1,
            self::Restaurants => 0,
            self::EverydayServices => 1,
            self::TouristAccommodation => 2,
        };
    }

    /**
     * @return list<string> BPE codes of the types counted in the family
     */
    public function types(): array
    {
        return match ($this) {
            self::ShopsTotal => array_map(static fn (ShopType $type): string => $type->value, ShopType::cases()),
            self::ShopsLarge => self::shopsOf('B1'),
            self::ShopsFood => self::shopsOf('B2'),
            self::ShopsNonFood => self::shopsOf('B3'),
            self::GeneralPractitioners => ['D265'],
            // The specialities only, without the generalists, dentists, midwives and physiotherapists counted elsewhere.
            self::Specialists => ['D251', 'D252', 'D253', 'D254', 'D255', 'D256', 'D257', 'D258', 'D259', 'D261', 'D262', 'D266', 'D267', 'D268', 'D269', 'D270', 'D271', 'D272', 'D273', 'D274', 'D275', 'D276'],
            self::Dentists => ['D277'],
            self::Paramedics => ['D278', 'D279', 'D280'],
            self::Pharmacies => ['D307'],
            // Hospitals of every kind; emergency units and maternity wards sit inside them and are not counted again.
            self::Hospitals => ['D101', 'D102', 'D103', 'D104'],
            self::Nurseries => ['D502'],
            self::ElderlyHomes => ['D401'],
            self::SportsFacilities => ['F101', 'F102', 'F103', 'F106', 'F107', 'F108', 'F109', 'F110', 'F111', 'F113', 'F114', 'F116', 'F119', 'F120', 'F121', 'F127', 'F129', 'F130'],
            self::CulturalFacilities => ['F303', 'F305', 'F307'],
            self::Restaurants => ['A504'],
            self::EverydayServices => ['A203', 'A206', 'A207', 'A208', 'A501', 'A506', 'A507'],
            self::TouristAccommodation => ['G102', 'G103'],
        };
    }

    /**
     * @return list<string>
     */
    private static function shopsOf(string $subDomain): array
    {
        return array_values(array_map(
            static fn (ShopType $type): string => $type->value,
            array_filter(ShopType::cases(), static fn (ShopType $type): bool => $type->subDomain() === $subDomain),
        ));
    }
}
