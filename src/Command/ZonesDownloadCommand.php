<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\Departement;
use App\Service\Import\CsvReader;
use App\Service\Import\XlsxReader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'app:zones:download',
    description: 'Refresh the vendored unité urbaine reference files (resources/geo/)',
    help: <<<'HELP'
        Downloads the full source files, then keeps only what is imported: the communes belonging to an
        unité urbaine, in France métropolitaine (the source also lists the ~27 000 communes outside any
        unité urbaine, and overseas territories). The three outputs share the same commune set.
        HELP,
)]
final class ZonesDownloadCommand extends Command
{
    // Hardcoded on purpose — a one-off refresh, not a configurable system.
    private const string COMMUNES_GEOJSON_URL = 'https://raw.githubusercontent.com/gregoiredavid/france-geojson/master/communes-version-simplifiee.geojson';

    // "Communes et villes de France" (data.gouv.fr) — stable resource
    // redirect, always resolves to the publisher's current version.
    private const string COMMUNES_CSV_URL = 'https://www.data.gouv.fr/api/1/datasets/r/c63fd0b1-7987-46f6-b779-8b3ed889090c';

    // INSEE "aires d'attraction des villes 2020", communes composition, as of
    // 1 January of the vintage in the file name — bump it to refresh. See
    // https://www.insee.fr/fr/information/4803954
    private const string ATTRACTION_AREAS_ZIP_URL = 'https://www.insee.fr/fr/statistiques/fichier/4803954/AAV2020_au_01-01-2026.zip';

    private const string ATTRACTION_AREAS_SHEET = 'Composition_communale';

    private const array ATTRACTION_AREAS_CSV_COLUMNS = ['code_insee', 'code_aav', 'nom_aav', 'categorie'];

    private const string STATUS_OUTSIDE_UNITE_URBAINE = 'H';

    private const array CSV_COLUMNS = ['code_insee', 'nom_standard', 'dep_code', 'population', 'code_unite_urbaine', 'nom_unite_urbaine'];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CsvReader $csvReader,
        private readonly XlsxReader $xlsxReader,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // The full communes GeoJSON is ~18 MB, decoded into PHP arrays.
        ini_set('memory_limit', '1G');

        $io = new SymfonyStyle($input, $output);
        $targetDir = \dirname(__DIR__, 2).'/resources/geo';

        try {
            $io->writeln('Downloading <info>communes-unites-urbaines.csv</info>...');
            $communeCodes = $this->writeCommunesCsv($targetDir.'/communes-unites-urbaines.csv');
            $io->writeln(\sprintf('  %d communes in an unité urbaine', \count($communeCodes)));

            $io->writeln('Downloading <info>communes-aires-attraction.csv</info>...');
            $io->writeln(\sprintf("  %d communes with an aire d'attraction category", $this->writeAttractionAreasCsv($targetDir.'/communes-aires-attraction.csv', $communeCodes)));

            $io->writeln('Downloading <info>communes.geojson</info>...');
            $kept = $this->writeCommunesGeojson($targetDir.'/communes.geojson', $communeCodes);
            $io->writeln(\sprintf('  %d boundaries kept', $kept));
        } catch (ExceptionInterface $e) {
            $io->error(\sprintf('Download failed: %s', $e->getMessage()));

            return Command::FAILURE;
        }

        $io->success('Reference files refreshed. Review with `git diff resources/geo/` before committing.');

        return Command::SUCCESS;
    }

    /**
     * @return array<string, true> codes of the kept communes
     */
    private function writeCommunesCsv(string $targetPath): array
    {
        $source = tempnam(sys_get_temp_dir(), 'communes-');
        if (false === $source) {
            throw new \RuntimeException('Cannot create a temporary file.');
        }

        try {
            $handle = fopen($source, 'w') ?: throw new \RuntimeException("Cannot write {$source}");
            foreach ($this->httpClient->stream($this->httpClient->request('GET', self::COMMUNES_CSV_URL)) as $chunk) {
                fwrite($handle, $chunk->getContent());
            }

            fclose($handle);

            return $this->filterCommunesCsv($source, $targetPath);
        } finally {
            unlink($source);
        }
    }

    /**
     * @return array<string, true>
     */
    private function filterCommunesCsv(string $sourcePath, string $targetPath): array
    {
        $out = fopen($targetPath, 'w') ?: throw new \RuntimeException("Cannot write {$targetPath}");
        fputcsv($out, self::CSV_COLUMNS, escape: '\\');

        $kept = [];
        foreach ($this->csvReader->read($sourcePath) as $row) {
            if (self::STATUS_OUTSIDE_UNITE_URBAINE === $row['statut_commune_unite_urbaine'] || null === Departement::tryFrom($row['dep_code'])) {
                continue;
            }

            fputcsv($out, array_map(static fn (string $column): string => $row[$column], self::CSV_COLUMNS), escape: '\\');
            $kept[$row['code_insee']] = true;
        }

        fclose($out);

        return $kept;
    }

    /**
     * @param array<string, true> $communeCodes
     *
     * @return int number of communes written
     */
    private function writeAttractionAreasCsv(string $targetPath, array $communeCodes): int
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'aav-zip-');
        $xlsxPath = tempnam(sys_get_temp_dir(), 'aav-xlsx-');
        if (false === $zipPath || false === $xlsxPath) {
            throw new \RuntimeException('Cannot create a temporary file.');
        }

        try {
            file_put_contents($zipPath, $this->httpClient->request('GET', self::ATTRACTION_AREAS_ZIP_URL)->getContent());

            $zip = new \ZipArchive();
            if (true !== $zip->open($zipPath)) {
                throw new \RuntimeException('The AAV download is not a valid zip file.');
            }

            // The archive holds a single workbook.
            $xlsxName = null;
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $name = (string) $zip->getNameIndex($i);
                if (str_ends_with($name, '.xlsx')) {
                    $xlsxName = $name;
                }
            }

            if (null === $xlsxName) {
                throw new \RuntimeException('No .xlsx file found in the AAV archive.');
            }

            file_put_contents($xlsxPath, $zip->getFromName($xlsxName) ?: throw new \RuntimeException("Cannot extract {$xlsxName}"));
            $zip->close();

            return $this->filterAttractionAreas($xlsxPath, $targetPath, $communeCodes);
        } finally {
            unlink($zipPath);
            unlink($xlsxPath);
        }
    }

    /**
     * @param array<string, true> $communeCodes
     */
    private function filterAttractionAreas(string $xlsxPath, string $targetPath, array $communeCodes): int
    {
        $out = fopen($targetPath, 'w') ?: throw new \RuntimeException("Cannot write {$targetPath}");
        fputcsv($out, self::ATTRACTION_AREAS_CSV_COLUMNS, escape: '\\');

        // The sheet starts with a few title lines, then the technical column names, then the data.
        $columns = null;
        $count = 0;
        foreach ($this->xlsxReader->read($xlsxPath, self::ATTRACTION_AREAS_SHEET) as $row) {
            if (null === $columns) {
                if ('CODGEO' === ($row[0] ?? null)) {
                    $columns = array_flip($row);
                }

                continue;
            }

            $code = $row[$columns['CODGEO']] ?? '';
            if (!isset($communeCodes[$code])) {
                continue;
            }

            fputcsv($out, [$code, $row[$columns['AAV2020']], $row[$columns['LIBAAV2020']], $row[$columns['CATEAAV2020']]], escape: '\\');
            ++$count;
        }

        fclose($out);

        if (null === $columns) {
            throw new \RuntimeException('The AAV sheet has no CODGEO header: the file layout changed.');
        }

        return $count;
    }

    /**
     * @param array<string, true> $communeCodes
     */
    private function writeCommunesGeojson(string $targetPath, array $communeCodes): int
    {
        /** @var array{features: list<array{properties: array{code: string, nom: string}, geometry: ?array<string, mixed>}>} $data */
        $data = $this->httpClient->request('GET', self::COMMUNES_GEOJSON_URL)->toArray();

        $features = [];
        foreach ($data['features'] as $feature) {
            if (isset($communeCodes[$feature['properties']['code']])) {
                $features[] = [
                    'type' => 'Feature',
                    'properties' => ['code' => $feature['properties']['code'], 'nom' => $feature['properties']['nom']],
                    'geometry' => $feature['geometry'],
                ];
            }
        }

        file_put_contents($targetPath, json_encode(['type' => 'FeatureCollection', 'features' => $features], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));

        return \count($features);
    }
}
