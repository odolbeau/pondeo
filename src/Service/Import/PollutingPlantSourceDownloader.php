<?php

declare(strict_types=1);

namespace App\Service\Import;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

/**
 * Refreshes `resources/geo/etablissements-pollueurs.csv` from Géorisques'
 * "Registre des émissions polluantes" (IREP): the industrial establishments
 * declaring emissions of pollutants. The source gives a name, a position and a
 * postal code, and nothing more.
 */
final readonly class PollutingPlantSourceDownloader
{
    public function __construct(
        private GeorisquesFeatureDownloader $featureDownloader,
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
        $progress('Downloading etablissements-pollueurs.csv...');

        $pois = [];
        foreach ($this->featureDownloader->download('ETABLISSEMENTS_POLLUEURS', 'identifiant') as $feature) {
            $properties = $feature['properties'];
            [$lon, $lat] = $feature['geometry']['coordinates'];

            $pois[$properties['identifiant']] = [
                'name' => trim($properties['nom']),
                'lat' => $lat,
                'lon' => $lon,
                'address' => trim($properties['cp']),
                'activity' => '',
            ];
        }

        $progress(\sprintf('  %d establishments', $this->fileWriter->write($targetDir.'/etablissements-pollueurs.csv', $pois)));
    }
}
