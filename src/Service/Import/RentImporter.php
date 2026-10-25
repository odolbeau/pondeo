<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Enum\CriterionValueType;

/**
 * Imports the advertised rents (see `docs/criteria-rents.md`): one rent per m²
 * criterion per property type. A rent is not additive, so a unité urbaine's
 * rent is its communes' rents averaged with their number of unfurnished
 * private rentals (latest census) as weight, read from the housing file.
 */
final readonly class RentImporter
{
    public const string GROUP = 'Location';

    /**
     * Indexed by the CSV property type.
     *
     * @var array<string, array{code: string, label: string, position: int}>
     */
    public const array CRITERIA = [
        'appartement' => ['code' => 'loyer_m2_appartement', 'label' => 'Loyer au m² (appartements)', 'position' => 0],
        'appartement_t1_t2' => ['code' => 'loyer_m2_appartement_t1_t2', 'label' => 'Loyer au m² (appartements T1-T2)', 'position' => 1],
        'appartement_t3_plus' => ['code' => 'loyer_m2_appartement_t3_plus', 'label' => 'Loyer au m² (appartements T3 et plus)', 'position' => 2],
        'maison' => ['code' => 'loyer_m2_maison', 'label' => 'Loyer au m² (maisons)', 'position' => 3],
    ];

    private const string WEIGHT_STATUS = 'locataires_prives';

    public function __construct(
        private CsvCriterionImporter $csvCriterionImporter,
        private CsvReader $csvReader,
    ) {
    }

    /**
     * @return int number of (unité urbaine, period) values upserted
     */
    public function import(string $rentsCsvPath, string $housingCsvPath): int
    {
        $weightedPath = $this->withWeights($rentsCsvPath, $this->renters($housingCsvPath));

        try {
            $count = 0;
            foreach (self::CRITERIA as $type => ['code' => $code, 'label' => $label, 'position' => $position]) {
                $count += $this->csvCriterionImporter->import(new CriterionCsvSpec(
                    code: $code,
                    label: $label,
                    valueType: CriterionValueType::Decimal,
                    unit: '€/m²',
                    communeCodeColumn: 'code_insee',
                    periodColumn: 'year',
                    valueColumn: 'loyer_m2',
                    groupLabel: self::GROUP,
                    filterColumn: 'type',
                    filterValue: $type,
                    position: $position,
                    aggregation: Aggregation::WeightedMean,
                    weightColumn: 'nb_locataires',
                ), $weightedPath);
            }
        } finally {
            unlink($weightedPath);
        }

        return $count;
    }

    /**
     * @return array<string, float> renters indexed by commune code, from the latest census year
     */
    private function renters(string $housingCsvPath): array
    {
        /** @var array<int, array<string, float>> $byYear */
        $byYear = [];
        foreach ($this->csvReader->read($housingCsvPath) as $row) {
            if (self::WEIGHT_STATUS === $row['status']) {
                $byYear[(int) $row['year']][$row['code_insee']] = (float) $row['count'];
            }
        }

        if ([] === $byYear) {
            throw new \RuntimeException(\sprintf('No "%s" count in %s: refresh it with app:import:criteria:housing --download.', self::WEIGHT_STATUS, $housingCsvPath));
        }

        krsort($byYear);

        return reset($byYear);
    }

    /**
     * @param array<string, float> $renters
     *
     * @return string the path of a temporary copy of the rents with a `nb_locataires` column; a commune without renters weighs nothing
     */
    private function withWeights(string $rentsCsvPath, array $renters): string
    {
        $path = tempnam(sys_get_temp_dir(), 'loyers-ponderes-');
        if (false === $path) {
            throw new \RuntimeException('Cannot create a temporary file.');
        }

        $out = fopen($path, 'w') ?: throw new \RuntimeException("Cannot write {$path}");
        fputcsv($out, ['code_insee', 'year', 'type', 'nb_locataires', 'loyer_m2'], escape: '\\');
        foreach ($this->csvReader->read($rentsCsvPath) as $row) {
            fputcsv($out, [$row['code_insee'], $row['year'], $row['type'], $renters[$row['code_insee']] ?? 0, $row['loyer_m2']], escape: '\\');
        }

        fclose($out);

        return $path;
    }
}
