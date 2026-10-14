<?php

declare(strict_types=1);

namespace App\Service\Import;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Refreshes `resources/geo/musees.csv` from the Ministry of Culture's
 * "Répertoire des Musées de France" (base Muséofile). A museum without
 * coordinates is left out.
 */
final readonly class MuseumSourceDownloader
{
    private const string SOURCE_URL = 'https://ministere-culture.s3.sbg.io.cloud.ovh.net/POP/museofile.csv';

    public function __construct(
        private HttpClientInterface $httpClient,
        private CsvReader $csvReader,
        private PoiSourceFileWriter $fileWriter,
    ) {
    }

    /**
     * @param \Closure(string): void $progress
     *
     * @throws ExceptionInterface when the download fails
     */
    public function download(string $targetDir, \Closure $progress): void
    {
        $progress('Downloading musees.csv...');

        $sourcePath = tempnam(sys_get_temp_dir(), 'museofile-') ?: throw new \RuntimeException('Cannot create a temporary file.');
        file_put_contents($sourcePath, $this->httpClient->request('GET', self::SOURCE_URL)->getContent());

        $pois = [];
        foreach ($this->csvReader->read($sourcePath, '|') as $row) {
            if (1 !== preg_match('/^\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)\s*$/', $row['Coordonnees'], $coordinates)) {
                continue;
            }

            $pois[$row['Identifiant']] = [
                'name' => trim($row['Nom_officiel']),
                'lat' => $coordinates[1],
                'lon' => $coordinates[2],
                'address' => implode(', ', array_filter([trim($row['Adresse']), trim($row['Code_postal'].' '.$row['Ville'])])),
                'activity' => str_replace(';', ', ', trim($row['Domaine_thematique'])),
            ];
        }

        unlink($sourcePath);

        $progress(\sprintf('  %d museums', $this->fileWriter->write($targetDir.'/musees.csv', $pois)));
    }
}
