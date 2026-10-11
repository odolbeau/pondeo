<?php

declare(strict_types=1);

namespace App\Service\Import;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Refreshes `resources/geo/prix-immobilier.csv` from the "Statistiques DVF"
 * dataset (Etalab/DGFiP via data.gouv.fr, Licence Ouverte). The source has one
 * row per geographic scale and month; the vendored file keeps communes only,
 * folded to one row per commune, year and property type, with the sales count
 * so that prices can be averaged with their weight further up.
 */
final readonly class RealEstatePriceSourceDownloader
{
    // Stable resource redirect, always resolves to the publisher's current monthly file.
    private const string MONTHLY_CSV_URL = 'https://www.data.gouv.fr/api/1/datasets/r/03fba98d-885b-43c0-8986-d299cabc29da';

    public const array TYPES = ['apt_maison', 'appartement', 'maison'];

    private const array CSV_COLUMNS = ['code_insee', 'year', 'type', 'nb_ventes', 'prix_m2'];

    public function __construct(
        private HttpClientInterface $httpClient,
        private CsvReader $csvReader,
    ) {
    }

    /**
     * @param \Closure(string): void $progress
     *
     * @throws ExceptionInterface when the download fails
     */
    public function download(string $targetDir, \Closure $progress): void
    {
        $source = tempnam(sys_get_temp_dir(), 'dvf-');
        if (false === $source) {
            throw new \RuntimeException('Cannot create a temporary file.');
        }

        try {
            $progress('Downloading the monthly DVF statistics (~270 MB)...');
            $this->fetch($source);

            $progress('Aggregating per commune and year...');
            $progress(\sprintf('  %d rows', $this->write($source, $targetDir.'/prix-immobilier.csv')));
        } finally {
            unlink($source);
        }
    }

    /**
     * @throws ExceptionInterface
     */
    private function fetch(string $path): void
    {
        $out = fopen($path, 'w') ?: throw new \RuntimeException("Cannot write {$path}");

        try {
            $response = $this->httpClient->request('GET', self::MONTHLY_CSV_URL, ['buffer' => false, 'timeout' => 60]);
            foreach ($this->httpClient->stream($response) as $chunk) {
                fwrite($out, $chunk->getContent());
            }
        } finally {
            fclose($out);
        }
    }

    private function write(string $sourcePath, string $targetPath): int
    {
        /** @var array<string, array{sales: int, weighted: float}> $totals indexed by "commune|year|type" */
        $totals = [];
        foreach ($this->csvReader->read($sourcePath) as $row) {
            if ('commune' !== $row['echelle_geo']) {
                continue;
            }

            $year = substr($row['annee_mois'], 0, 4);
            foreach (self::TYPES as $type) {
                $sales = (int) $row['nb_ventes_'.$type];
                $price = $row['moy_prix_m2_'.$type];
                if ($sales <= 0 || !is_numeric($price)) {
                    continue;
                }

                $key = $this->communeCode($row['code_geo']).'|'.$year.'|'.$type;
                $totals[$key] ??= ['sales' => 0, 'weighted' => 0.0];
                $totals[$key]['sales'] += $sales;
                $totals[$key]['weighted'] += $sales * (float) $price;
            }
        }

        ksort($totals);

        $out = fopen($targetPath, 'w') ?: throw new \RuntimeException("Cannot write {$targetPath}");
        fputcsv($out, self::CSV_COLUMNS, escape: '\\');
        foreach ($totals as $key => $total) {
            [$commune, $year, $type] = explode('|', $key);
            fputcsv($out, [$commune, $year, $type, $total['sales'], round($total['weighted'] / $total['sales'], 2)], escape: '\\');
        }

        fclose($out);

        return \count($totals);
    }

    /**
     * DVF reports Paris, Lyon and Marseille per arrondissement, which are not
     * communes of the zoning: fold them back into their commune.
     */
    private function communeCode(string $code): string
    {
        return match (true) {
            1 === preg_match('/^751\d\d$/', $code) => '75056',
            1 === preg_match('/^6938\d$/', $code) => '69123',
            1 === preg_match('/^132\d\d$/', $code) => '13055',
            default => $code,
        };
    }
}
