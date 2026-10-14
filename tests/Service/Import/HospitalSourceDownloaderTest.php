<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Service\Import\CsvReader;
use App\Service\Import\HospitalSourceDownloader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class HospitalSourceDownloaderTest extends TestCase
{
    public function testWritesTheHospitalsWithTheirEmergencyDepartments(): void
    {
        $structures = [
            // Geocoded by the directory: kept as is.
            $this->establishment('010000001', 'CH DE BOURG', '355', ['directionLatitude' => '46.203678', 'directionLongitude' => '5.219262']),
            // Only a Lambert-93 position: converted.
            $this->establishment('020000394', 'CH LAON', '355', ['coordonneeX' => '744242.0', 'coordonneeY' => '6940498.9']),
            $this->establishment('030000001', 'HOPITAL DES ENFANTS', '365', ['directionLatitude' => '43.7', 'directionLongitude' => '7.2']),
            $this->establishment('040000001', 'CLINIQUE SANS URGENCES', '365', ['directionLatitude' => '47.0', 'directionLongitude' => '-2.0']),
            // Not hospitals, closed, or impossible to place: left out.
            $this->establishment('050000001', 'CENTRE DE DIALYSE', '141', ['directionLatitude' => '45.0', 'directionLongitude' => '4.0']),
            $this->establishment('060000001', 'CH FERME', '355', ['directionLatitude' => '45.0', 'directionLongitude' => '4.0'], closed: true),
            $this->establishment('070000001', 'CH SANS POSITION', '355', null),
            $this->establishment('970000001', 'CH OUTRE-MER', '355', ['coordonneeX' => '645000.0', 'coordonneeY' => '1770000.0']),
        ];
        $activities = [
            $this->activities('010000001', [$this->emergencyActivity('MO032', 'ME065')]),
            $this->activities('020000394', [$this->emergencyActivity('MO032', 'ME065'), $this->emergencyActivity('MO032', 'ME064')]),
            $this->activities('030000001', [$this->emergencyActivity('MO032', 'ME064')]),
            // Neither a SAMU/SMUR, nor an authorisation that is no longer active, is a department.
            $this->activities('040000001', [$this->emergencyActivity('MO030', 'ME000'), $this->emergencyActivity('MO032', 'ME065', 'I')]),
        ];

        $dir = sys_get_temp_dir().'/hospital-downloader-test-'.bin2hex(random_bytes(4));
        mkdir($dir);

        $progress = [];
        $this->downloader($structures, $activities)->download($dir, static function (string $message) use (&$progress): void {
            $progress[] = $message;
        });

        $rows = iterator_to_array(new CsvReader()->read($dir.'/hopitaux.csv'), false);
        unlink($dir.'/hopitaux.csv');
        rmdir($dir);

        self::assertSame([
            ['finess' => '010000001', 'name' => 'CH DE BOURG', 'type' => 'ch', 'lat' => '46.203678', 'lon' => '5.219262', 'address' => '1 RUE DU TEST, 01000 BOURG EN BRESSE', 'emergency' => '1', 'emergency_pediatric' => '0'],
            ['finess' => '020000394', 'name' => 'CH LAON', 'type' => 'ch', 'lat' => '49.562210', 'lon' => '3.611254', 'address' => '1 RUE DU TEST, 01000 BOURG EN BRESSE', 'emergency' => '1', 'emergency_pediatric' => '1'],
            ['finess' => '030000001', 'name' => 'HOPITAL DES ENFANTS', 'type' => 'clinic', 'lat' => '43.7', 'lon' => '7.2', 'address' => '1 RUE DU TEST, 01000 BOURG EN BRESSE', 'emergency' => '1', 'emergency_pediatric' => '1'],
            ['finess' => '040000001', 'name' => 'CLINIQUE SANS URGENCES', 'type' => 'clinic', 'lat' => '47.0', 'lon' => '-2.0', 'address' => '1 RUE DU TEST, 01000 BOURG EN BRESSE', 'emergency' => '0', 'emergency_pediatric' => '0'],
        ], $rows);
        self::assertContains('  3 emergency departments', $progress);
        self::assertContains('  4 establishments written to hopitaux.csv, 2 skipped for lack of a usable position', $progress);
    }

    /**
     * @param array<string, string>|null $coordinates
     *
     * @return array<string, mixed>
     */
    private function establishment(string $finess, string $name, string $category, ?array $coordinates, bool $closed = false): array
    {
        return [
            'informationsGeneralesEGE' => ['numFinessEge' => $finess, 'nomEgeLong' => $name, 'nomEgeCourt' => $name, 'dateFermeture' => $closed ? '2020-01-01' : null],
            'categorieentiteGeographiqueExercice' => $category,
            'etatObjet' => $closed ? 'I' : 'A',
            'adresse' => [[
                'ligneQuatre' => '1 RUE DU TEST',
                'codePostal' => '01000',
                'ligneAcheminement' => 'BOURG EN BRESSE',
                'coordonneesGeographique' => null === $coordinates ? null : array_merge(['coordonneeX' => null, 'coordonneeY' => null, 'directionLatitude' => null, 'directionLongitude' => null], $coordinates),
            ]],
        ];
    }

    /**
     * @param list<array<string, mixed>> $activities
     *
     * @return array<string, mixed>
     */
    private function activities(string $finess, array $activities): array
    {
        return ['numFinessEge' => $finess, 'activitesExercees' => $activities];
    }

    /**
     * @return array<string, mixed>
     */
    private function emergencyActivity(string $modality, string $mention, string $state = 'A'): array
    {
        return [
            'caracteristiquesGeneriques' => ['etatObjet' => $state, 'dateFinEffectiveActivite' => null],
            'nature' => ['caracteristiquesSpecifiques' => ['typeActiviteAMM' => ['activiteAMM' => 'QA014', 'modaliteAMM' => $modality, 'mentionAMM' => $mention]]],
        ];
    }

    /**
     * @param list<array<string, mixed>> $structures
     * @param list<array<string, mixed>> $activities
     */
    private function downloader(array $structures, array $activities): HospitalSourceDownloader
    {
        $structuresJson = (string) gzencode(json_encode(['pmej' => [['ege' => $structures]]], \JSON_THROW_ON_ERROR));
        // The activities feed lists the same establishments, grouped by legal entity.
        $activitiesJson = (string) gzencode(json_encode(['pmej' => [['ege' => $activities]]], \JSON_THROW_ON_ERROR));

        return new HospitalSourceDownloader(new MockHttpClient(static fn (string $method, string $url): MockResponse => new MockResponse(str_contains($url, 'cd493959') ? $structuresJson : $activitiesJson)));
    }
}
