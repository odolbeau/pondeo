<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Family of associations, grouping the top-level themes of the RNA's WALDEC
 * nomenclature (the first three digits of an object code, see
 * `docs/criteria-associations.md`). The other themes (politics, trade
 * representation, religion…) have no family: they only count in the totals.
 */
enum AssociationFamily: string
{
    case Sport = 'sport';
    case Culture = 'culture';
    case Loisirs = 'loisirs';
    case Solidarite = 'solidarite';
    case Sante = 'sante';
    case Education = 'education';
    case Environnement = 'environnement';

    public function label(): string
    {
        return match ($this) {
            self::Sport => 'Sport et plein air',
            self::Culture => 'Culture et patrimoine',
            self::Loisirs => 'Loisirs, amicales et vie de quartier',
            self::Solidarite => 'Solidarité et action sociale',
            self::Sante => 'Santé et médico-social',
            self::Education => 'Éducation et recherche',
            self::Environnement => 'Environnement et cadre de vie',
        };
    }

    /**
     * @return list<string> the three-digit themes of the nomenclature
     */
    public function themes(): array
    {
        return match ($this) {
            self::Sport => ['011', '013'],
            self::Culture => ['006', '010'],
            self::Loisirs => ['007', '009', '014'],
            self::Solidarite => ['019', '020', '021'],
            self::Sante => ['017', '018'],
            self::Education => ['015', '016'],
            self::Environnement => ['024'],
        };
    }

    /**
     * @param string $objectCode the six-digit `objet_social1` of an association
     */
    public static function fromObjectCode(string $objectCode): ?self
    {
        $theme = substr($objectCode, 0, 3);
        foreach (self::cases() as $family) {
            if (\in_array($theme, $family->themes(), true)) {
                return $family;
            }
        }

        return null;
    }
}
