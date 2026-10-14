<?php

declare(strict_types=1);

namespace App\Enum;

use App\Service\Import\AgeStructureImporter;
use App\Service\Import\AssociationsImporter;
use App\Service\Import\EducationImporter;
use App\Service\Import\EmploymentImporter;
use App\Service\Import\HousingImporter;
use App\Service\Import\IncomeImporter;
use App\Service\Import\PopulationTrendDeriver;
use App\Service\Import\RealEstatePriceImporter;
use App\Service\Import\SocioProfessionalImporter;
use App\Service\Import\TaxIncomeImporter;

/**
 * Thematic section of an unité urbaine's detail page: it gathers criterion
 * groups, and the page's own blocks (pyramid, stations, points of interest).
 */
enum DetailSection: string
{
    case Population = 'population';
    case Economie = 'economie';
    case Logement = 'logement';
    case SanteSportCulture = 'sante-sport-culture';
    case CadreDeVie = 'cadre-de-vie';
    case Autres = 'autres';
    case PointsInteret = 'points-interet';

    public function label(): string
    {
        return match ($this) {
            self::Population => 'Population',
            self::Economie => 'Économie',
            self::Logement => 'Logement',
            self::SanteSportCulture => 'Santé, sport et culture',
            self::CadreDeVie => 'Cadre de vie',
            self::Autres => 'Autres',
            self::PointsInteret => 'Points d\'intérêt',
        };
    }

    /**
     * The criterion groups of the section, in display order.
     *
     * @return list<string>
     */
    public function groups(): array
    {
        return match ($this) {
            self::Population => [PopulationTrendDeriver::GROUP, AgeStructureImporter::GROUP, SocioProfessionalImporter::GROUP, EducationImporter::GROUP],
            self::Economie => [IncomeImporter::GROUP, TaxIncomeImporter::GROUP, EmploymentImporter::GROUP],
            self::Logement => [HousingImporter::GROUP, RealEstatePriceImporter::GROUP],
            self::SanteSportCulture => [EquipmentFamily::GROUP_HEALTH, EquipmentFamily::GROUP_CARE, EquipmentFamily::GROUP_SPORT_CULTURE],
            self::CadreDeVie => [EquipmentFamily::GROUP_SHOPS, EquipmentFamily::GROUP_SERVICES, AssociationsImporter::GROUP],
            self::Autres, self::PointsInteret => [],
        };
    }

    public static function fromGroup(string $group): self
    {
        foreach (self::cases() as $section) {
            if (\in_array($group, $section->groups(), true)) {
                return $section;
            }
        }

        return self::Autres;
    }
}
