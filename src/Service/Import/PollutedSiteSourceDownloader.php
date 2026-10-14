<?php

declare(strict_types=1);

namespace App\Service\Import;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

/**
 * Refreshes `resources/geo/sites-pollues.csv` from Géorisques' "Secteurs
 * d'information sur les sols" (SIS, BRGM): the sites the State has classified
 * as polluted. The sites still under instruction are left out.
 */
final readonly class PollutedSiteSourceDownloader
{
    private const string FALLBACK_NAME = 'Site pollué';

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
        $progress('Downloading sites-pollues.csv...');

        $pois = [];
        foreach ($this->featureDownloader->download('SSP_CLASSIF_SIS_GE', 'code_metier') as $feature) {
            $properties = $feature['properties'];
            $name = trim($properties['nom_site']) ?: trim($properties['nom_etablissement']);

            $pois[$properties['code_metier']] = [
                'name' => '' === $name ? self::FALLBACK_NAME : $name,
                'lat' => $properties['y_wgs84'],
                'lon' => $properties['x_wgs84'],
                'address' => implode(', ', array_filter([trim($properties['adresse']), trim($properties['code_postal'].' '.$properties['nom_commune'])])),
                'activity' => '',
            ];
        }

        $progress(\sprintf('  %d sites', $this->fileWriter->write($targetDir.'/sites-pollues.csv', $pois)));
    }
}
