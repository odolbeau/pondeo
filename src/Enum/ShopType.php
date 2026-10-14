<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The shop types (BPE domain B) of the series that INSEE keeps comparable
 * over time, by their BPE code: the unit the vendored shop file is kept in
 * (see `docs/criteria-commerces.md`). The type B326 (EV charging stations)
 * is not part of that series.
 */
enum ShopType: string
{
    case B103 = 'B103';
    case B104 = 'B104';
    case B105 = 'B105';
    case B201 = 'B201';
    case B202 = 'B202';
    case B204 = 'B204';
    case B205 = 'B205';
    case B206 = 'B206';
    case B207 = 'B207';
    case B208 = 'B208';
    case B209 = 'B209';
    case B210 = 'B210';
    case B302 = 'B302';
    case B303 = 'B303';
    case B304 = 'B304';
    case B306 = 'B306';
    case B307 = 'B307';
    case B308 = 'B308';
    case B309 = 'B309';
    case B310 = 'B310';
    case B311 = 'B311';
    case B312 = 'B312';
    case B313 = 'B313';
    case B315 = 'B315';
    case B316 = 'B316';
    case B317 = 'B317';
    case B318 = 'B318';
    case B319 = 'B319';
    case B320 = 'B320';
    case B321 = 'B321';
    case B322 = 'B322';
    case B323 = 'B323';
    case B324 = 'B324';
    case B325 = 'B325';

    public function label(): string
    {
        return match ($this) {
            self::B103 => 'Grande surface de bricolage',
            self::B104 => 'Hypermarché et grand magasin',
            self::B105 => 'Supermarché et magasin multi-commerce',
            self::B201 => 'Supérette',
            self::B202 => 'Épicerie',
            self::B204 => 'Boucherie charcuterie',
            self::B205 => 'Produits surgelés',
            self::B206 => 'Poissonnerie',
            self::B207 => 'Boulangerie-pâtisserie',
            self::B208 => 'Commerce spécialisé en fruits et légumes',
            self::B209 => 'Commerce de boissons',
            self::B210 => 'Autres commerces alimentaires',
            self::B302 => 'Magasin de vêtements',
            self::B303 => "Magasin d'équipements du foyer",
            self::B304 => 'Magasin de chaussures',
            self::B306 => 'Magasin de meubles',
            self::B307 => "Magasin d'articles de sports et de loisirs",
            self::B308 => 'Magasin de revêtements murs et sols',
            self::B309 => 'Droguerie, quincaillerie, bricolage',
            self::B310 => 'Parfumerie-cosmétique',
            self::B311 => 'Horlogerie-bijouterie',
            self::B312 => 'Fleuriste, jardinerie, animalerie',
            self::B313 => "Magasin d'optique",
            self::B315 => 'Magasin de matériel médical et orthopédique',
            self::B316 => 'Station-service',
            self::B317 => 'Commerce de tissus et mercerie',
            self::B318 => 'Commerce de jeux et jouets',
            self::B319 => 'Maroquinerie et articles de voyage',
            self::B320 => 'Commerce de combustibles domestiques',
            self::B321 => "Magasin d'électroménager, audio, vidéo et informatique",
            self::B322 => 'Magasin de matériels de télécommunication',
            self::B323 => "Commerce de biens d'occasion",
            self::B324 => 'Librairie',
            self::B325 => 'Papeterie et presse',
        };
    }

    /**
     * The BPE sub-domain the type belongs to: B1 large stores, B2 food shops,
     * B3 non-food shops. It is the first two characters of the code.
     */
    public function subDomain(): string
    {
        return substr($this->value, 0, 2);
    }
}
