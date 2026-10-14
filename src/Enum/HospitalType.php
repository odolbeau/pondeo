<?php

declare(strict_types=1);

namespace App\Enum;

/** The kinds of hospitals; the value is the `type` column of the vendored file. */
enum HospitalType: string
{
    case University = 'chu';
    case General = 'ch';
    case Clinic = 'clinic';
    case Cancer = 'cancer';
    case Army = 'army';

    /** Code of the POI category of this type. */
    public function categoryCode(): string
    {
        return match ($this) {
            self::University => 'hospital_university',
            self::General => 'hospital_general',
            self::Clinic => 'hospital_clinic',
            self::Cancer => 'hospital_cancer',
            self::Army => 'hospital_army',
        };
    }

    /**
     * The type of an establishment from its FINESS category (the
     * "categorieentiteGeographiqueExercice" code), or null when it is not a
     * hospital: dialysis, psychiatry, rehabilitation, nursing homes...
     */
    public static function fromFinessCategory(string $category): ?self
    {
        return match ($category) {
            '101' => self::University,
            '355', '106' => self::General,
            '365', '128', '129', '122', '697' => self::Clinic,
            '131' => self::Cancer,
            '114' => self::Army,
            default => null,
        };
    }
}
