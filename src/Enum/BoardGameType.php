<?php

declare(strict_types=1);

namespace App\Enum;

/** The kinds of board game places; the value is the `type` column of the vendored files. */
enum BoardGameType: string
{
    case Club = 'club';
    case Library = 'ludo';
    case Network = 'rese';
    case OtherAssociation = 'othe';
    case Shop = 'shop';
    case Cafe = 'cafe';
    case ShopCafe = 'shop-cafe';
    case Lodge = 'gite';

    public function group(): BoardGameGroup
    {
        return match ($this) {
            self::Club, self::Library, self::Network, self::OtherAssociation => BoardGameGroup::Association,
            self::Shop, self::Cafe, self::ShopCafe, self::Lodge => BoardGameGroup::Store,
        };
    }

    /** Code of the POI category of this type. */
    public function categoryCode(): string
    {
        return match ($this) {
            self::Club => 'board_game_club',
            self::Library => 'board_game_library',
            self::Network => 'board_game_network',
            self::OtherAssociation => 'board_game_other_association',
            self::Shop => 'board_game_shop',
            self::Cafe => 'board_game_cafe',
            self::ShopCafe => 'board_game_shop_cafe',
            self::Lodge => 'board_game_lodge',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Club => 'Club de jeux',
            self::Library => 'Ludothèque',
            self::Network => 'Réseau national de jeux',
            self::OtherAssociation => 'Autre association ludique',
            self::Shop => 'Boutique de jeux',
            self::Cafe => 'Bar à jeux',
            self::ShopCafe => 'Boutique et bar à jeux',
            self::Lodge => 'Gîte ludique',
        };
    }
}
