<?php

declare(strict_types=1);

namespace App\Service\Import;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Refreshes `resources/geo/loyers-annonces.csv` from the ANIL "Carte des
 * loyers" (indicateurs de loyers d'annonce par commune, data.gouv.fr, Licence
 * Ouverte): one file per property type, folded into one row per commune and
 * type.
 */
final readonly class RentSourceDownloader
{
    // Each edition is a dataset of its own on data.gouv.fr, and editions cannot
    // be compared: a refresh to a new edition updates the year and the resources.
    public const int EDITION = 2025;

    /** Resource ids of the edition's files, indexed by the property type written in the CSV. */
    private const array RESOURCES = [
        'appartement' => '55b34088-0964-415f-9df7-d87dd98a09be',
        'appartement_t1_t2' => '14a1fe11-b2d1-49b3-9f6b-83d12df9482c',
        'appartement_t3_plus' => '5e3b28a4-cf56-43a3-ae79-43cceeb27f8c',
        'maison' => '129f764d-b613-44e4-952c-5ff50a8c9b73',
    ];

    private const string RESOURCE_URL = 'https://www.data.gouv.fr/api/1/datasets/r/';

    private const array CSV_COLUMNS = ['code_insee', 'year', 'type', 'niveau', 'nb_annonces', 'loyer_m2'];

    public function __construct(
        private HttpClientInterface $httpClient,
        private CsvReader $csvReader,
    ) {
    }

    /**
     * @param \Closure(string): void $progress
     *
     * @throws ExceptionInterface when a download fails
     */
    public function download(string $targetDir, \Closure $progress): void
    {
        $out = fopen($targetDir.'/loyers-annonces.csv', 'w') ?: throw new \RuntimeException("Cannot write {$targetDir}/loyers-annonces.csv");
        fputcsv($out, self::CSV_COLUMNS, escape: '\\');

        try {
            foreach (self::RESOURCES as $type => $resourceId) {
                $progress(\sprintf('Downloading the %s rents...', $type));
                $rows = $this->fetch($resourceId);
                foreach ($rows as $commune => $row) {
                    fputcsv($out, [$commune, self::EDITION, $type, $row['niveau'], $row['annonces'], round($row['loyer'], 2)], escape: '\\');
                }

                $progress(\sprintf('  %d communes', \count($rows)));
            }
        } finally {
            fclose($out);
        }
    }

    /**
     * @return array<string, array{niveau: string, annonces: int, loyer: float}> indexed by commune code, sorted
     *
     * @throws ExceptionInterface
     */
    private function fetch(string $resourceId): array
    {
        $source = tempnam(sys_get_temp_dir(), 'loyers-');
        if (false === $source) {
            throw new \RuntimeException('Cannot create a temporary file.');
        }

        try {
            file_put_contents($source, $this->httpClient->request('GET', self::RESOURCE_URL.$resourceId)->getContent());

            /** @var array<string, array{niveau: string, annonces: int, weighted: float, weight: int}> $communes */
            $communes = [];
            foreach ($this->csvReader->read($source, ';') as $row) {
                $commune = $this->communeCode($row['INSEE_C']);
                $annonces = (int) $row['nbobs_com'];
                $loyer = (float) str_replace(',', '.', $row['loypredm2']);

                // An arrondissement folded into its commune weighs its number of ads, the
                // only figure the source gives for it. Every other commune comes once.
                $communes[$commune] ??= ['niveau' => strtolower($row['TYPPRED']), 'annonces' => 0, 'weighted' => 0.0, 'weight' => 0];
                $communes[$commune]['annonces'] += $annonces;
                $communes[$commune]['weighted'] += max($annonces, 1) * $loyer;
                $communes[$commune]['weight'] += max($annonces, 1);
            }
        } finally {
            unlink($source);
        }

        ksort($communes, \SORT_STRING);

        return array_map(static fn (array $commune): array => [
            'niveau' => $commune['niveau'],
            'annonces' => $commune['annonces'],
            'loyer' => $commune['weighted'] / $commune['weight'],
        ], $communes);
    }

    /**
     * The source reports Paris, Lyon and Marseille per arrondissement, which
     * are not communes of the zoning: fold them back into their commune.
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
