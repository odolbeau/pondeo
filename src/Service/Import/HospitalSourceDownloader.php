<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Enum\HospitalType;
use JsonMachine\Items;
use JsonMachine\JsonDecoder\ExtJsonDecoder;
use proj4php\Point;
use proj4php\Proj;
use proj4php\Proj4php;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Refreshes `resources/geo/hopitaux.csv` from the FINESS directory's
 * next-generation open data feeds ("FINESS - Structures" and "FINESS -
 * Activités", Agence du Numérique en Santé, data.gouv.fr, Licence Ouverte).
 * The legacy "Extraction du Fichier des établissements" is frozen since
 * May 2026.
 *
 * Keeps the open geographic establishments of the hospital categories (see
 * HospitalType), plus every establishment practising emergency medicine as a
 * "structure des urgences", flagged `emergency`. Only structures: the
 * directory's professionals are not in these feeds.
 */
final readonly class HospitalSourceDownloader
{
    // Stable resource redirects, always resolve to the publisher's latest daily file.
    private const string STRUCTURES_URL = 'https://www.data.gouv.fr/api/1/datasets/r/cd493959-fb03-41e5-9347-0edd14dfbc22';

    private const string ACTIVITIES_URL = 'https://www.data.gouv.fr/api/1/datasets/r/ed12913c-6bb2-4e47-8434-2f6e4f961c8e';

    private const array CSV_COLUMNS = ['finess', 'name', 'type', 'lat', 'lon', 'address', 'emergency', 'emergency_pediatric'];

    // Authorised activity codes (nomenclature of the "AMM" authorisations).
    private const string EMERGENCY_MEDICINE = 'QA014';

    private const string EMERGENCY_DEPARTMENT = 'MO032';

    private const string PEDIATRIC_MENTION = 'ME064';

    // Positions without WGS84 coordinates come in the projected system of
    // mainland France; the overseas ones use other, unlabelled systems.
    private const string LAMBERT_93 = '+proj=lcc +lat_0=46.5 +lon_0=3 +lat_1=49 +lat_2=44 +x_0=700000 +y_0=6600000 +ellps=GRS80 +towgs84=0,0,0,0,0,0,0 +units=m +no_defs';

    private const int LAMBERT_93_MIN_Y = 6_000_000;

    private const int LAMBERT_93_MAX_Y = 7_200_000;

    public function __construct(private HttpClientInterface $httpClient)
    {
    }

    /**
     * @param \Closure(string): void $progress
     *
     * @throws ExceptionInterface when the download fails
     */
    public function download(string $targetDir, \Closure $progress): void
    {
        $structures = $this->temporaryFile();
        $activities = $this->temporaryFile();

        try {
            $progress('Downloading the FINESS structures and activities...');
            $this->fetch(self::STRUCTURES_URL, $structures);
            $this->fetch(self::ACTIVITIES_URL, $activities);

            $emergencies = $this->readEmergencies($activities);
            $progress(\sprintf('  %d emergency departments', \count($emergencies)));

            ['written' => $written, 'unlocated' => $unlocated] = $this->writeHospitals($structures, $emergencies, $targetDir.'/hopitaux.csv');
            $progress(\sprintf('  %d establishments written to hopitaux.csv, %d skipped for lack of a usable position', $written, $unlocated));
        } finally {
            unlink($structures);
            unlink($activities);
        }
    }

    /**
     * The geographic establishments with an open emergency department, and
     * whether one of its structures is dedicated to children.
     *
     * @return array<string, bool> pediatric flag by establishment FINESS number
     */
    private function readEmergencies(string $activitiesPath): array
    {
        $emergencies = [];

        /** @var iterable<array{ege: list<array{numFinessEge: string, activitesExercees: list<array{caracteristiquesGeneriques: array{etatObjet: string, dateFinEffectiveActivite: ?string}, nature: array{caracteristiquesSpecifiques: array{typeActiviteAMM?: array{activiteAMM: string, modaliteAMM: string, mentionAMM: string}}}}>}>}> $legalEntities */
        $legalEntities = Items::fromFile('compress.zlib://'.$activitiesPath, ['pointer' => '/pmej', 'decoder' => new ExtJsonDecoder(true)]);
        foreach ($legalEntities as $legalEntity) {
            foreach ($legalEntity['ege'] as $establishment) {
                foreach ($establishment['activitesExercees'] as $activity) {
                    $generic = $activity['caracteristiquesGeneriques'];
                    $type = $activity['nature']['caracteristiquesSpecifiques']['typeActiviteAMM'] ?? null;
                    if (null === $type || 'A' !== $generic['etatObjet'] || null !== $generic['dateFinEffectiveActivite']) {
                        continue;
                    }

                    if (self::EMERGENCY_MEDICINE !== $type['activiteAMM'] || self::EMERGENCY_DEPARTMENT !== $type['modaliteAMM']) {
                        continue;
                    }

                    $number = $establishment['numFinessEge'];
                    $emergencies[$number] = ($emergencies[$number] ?? false) || self::PEDIATRIC_MENTION === $type['mentionAMM'];
                }
            }
        }

        return $emergencies;
    }

    /**
     * @param array<string, bool> $emergencies
     *
     * @return array{written: int, unlocated: int}
     */
    private function writeHospitals(string $structuresPath, array $emergencies, string $targetPath): array
    {
        $proj4 = new Proj4php();
        $proj4->addDef('EPSG:2154', self::LAMBERT_93);

        $lambert93 = new Proj('EPSG:2154', $proj4);
        $wgs84 = new Proj('EPSG:4326', $proj4);

        $out = fopen($targetPath, 'w') ?: throw new \RuntimeException("Cannot write {$targetPath}");
        fputcsv($out, self::CSV_COLUMNS, escape: '\\');

        $written = 0;
        $unlocated = 0;

        /** @var iterable<array{ege: list<array{informationsGeneralesEGE: array{numFinessEge: string, nomEgeLong: ?string, nomEgeCourt: ?string, dateFermeture: ?string}, categorieentiteGeographiqueExercice: ?string, etatObjet: string, adresse: list<array{ligneQuatre: ?string, codePostal: ?string, ligneAcheminement: ?string, coordonneesGeographique: ?array{coordonneeX: ?string, coordonneeY: ?string, directionLatitude: ?string, directionLongitude: ?string}}>}>}> $legalEntities */
        $legalEntities = Items::fromFile('compress.zlib://'.$structuresPath, ['pointer' => '/pmej', 'decoder' => new ExtJsonDecoder(true)]);
        foreach ($legalEntities as $legalEntity) {
            foreach ($legalEntity['ege'] as $establishment) {
                $general = $establishment['informationsGeneralesEGE'];
                $number = $general['numFinessEge'];
                if ('A' !== $establishment['etatObjet'] || null !== $general['dateFermeture']) {
                    continue;
                }

                $type = HospitalType::fromFinessCategory((string) $establishment['categorieentiteGeographiqueExercice']);
                if (null === $type) {
                    continue;
                }

                $position = $this->position($establishment['adresse'], $proj4, $lambert93, $wgs84);
                if (null === $position) {
                    ++$unlocated;

                    continue;
                }

                $name = $general['nomEgeLong'] ?? $general['nomEgeCourt'] ?? '';
                if ('' === $name) {
                    continue;
                }

                fputcsv($out, [
                    $number,
                    $name,
                    $type->value,
                    $position['lat'],
                    $position['lon'],
                    $position['address'],
                    \array_key_exists($number, $emergencies) ? '1' : '0',
                    ($emergencies[$number] ?? false) ? '1' : '0',
                ], escape: '\\');
                ++$written;
            }
        }

        fclose($out);

        return ['written' => $written, 'unlocated' => $unlocated];
    }

    /**
     * The first address the directory geocoded to WGS84; failing that, the
     * first with projected coordinates, converted.
     *
     * @param list<array{ligneQuatre: ?string, codePostal: ?string, ligneAcheminement: ?string, coordonneesGeographique: ?array{coordonneeX: ?string, coordonneeY: ?string, directionLatitude: ?string, directionLongitude: ?string}}> $addresses
     *
     * @return array{lat: string, lon: string, address: string}|null
     */
    private function position(array $addresses, Proj4php $proj4, Proj $lambert93, Proj $wgs84): ?array
    {
        $projected = null;
        foreach ($addresses as $address) {
            $coordinates = $address['coordonneesGeographique'];
            if (null === $coordinates) {
                continue;
            }

            if (is_numeric($coordinates['directionLatitude']) && is_numeric($coordinates['directionLongitude'])) {
                return ['lat' => $coordinates['directionLatitude'], 'lon' => $coordinates['directionLongitude'], 'address' => $this->formatAddress($address)];
            }

            $x = $coordinates['coordonneeX'];
            $y = $coordinates['coordonneeY'];
            if (null === $projected && is_numeric($x) && is_numeric($y) && (float) $y > self::LAMBERT_93_MIN_Y && (float) $y < self::LAMBERT_93_MAX_Y) {
                $projected = ['x' => (float) $x, 'y' => (float) $y, 'address' => $this->formatAddress($address)];
            }
        }

        if (null === $projected) {
            return null;
        }

        $point = $proj4->transform($lambert93, $wgs84, new Point($projected['x'], $projected['y']));

        \assert($point instanceof Point);
        /** @var array{0: float, 1: float} $lonLat */
        $lonLat = $point->toArray();

        return ['lat' => \sprintf('%.6F', $lonLat[1]), 'lon' => \sprintf('%.6F', $lonLat[0]), 'address' => $projected['address']];
    }

    /**
     * @param array{ligneQuatre: ?string, codePostal: ?string, ligneAcheminement: ?string} $address
     */
    private function formatAddress(array $address): string
    {
        $locality = trim(\sprintf('%s %s', $address['codePostal'], $address['ligneAcheminement']));

        return implode(', ', array_filter([$address['ligneQuatre'], $locality], static fn (?string $part): bool => null !== $part && '' !== $part));
    }

    /**
     * @throws ExceptionInterface when the download fails
     */
    private function fetch(string $url, string $targetPath): void
    {
        $handle = fopen($targetPath, 'w') ?: throw new \RuntimeException("Cannot write {$targetPath}");

        try {
            foreach ($this->httpClient->stream($this->httpClient->request('GET', $url)) as $chunk) {
                fwrite($handle, $chunk->getContent());
            }
        } finally {
            fclose($handle);
        }
    }

    private function temporaryFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'finess-');
        if (false === $path) {
            throw new \RuntimeException('Cannot create a temporary file.');
        }

        return $path;
    }
}
