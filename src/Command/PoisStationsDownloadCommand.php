<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Import\CsvReader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'app:pois:stations:download',
    description: 'Refresh the vendored railway stations list (resources/geo/gares-voyageurs.csv)',
    help: <<<'HELP'
        Downloads the "Gares de voyageurs" dataset (SNCF Gares & Connexions, data.gouv.fr, ODbL): the passenger
        stations of the French network, with WGS84 coordinates. The source writes the position as
        "latitude, longitude" in one cell; the vendored file splits it into <comment>lat</comment> and
        <comment>lon</comment>. A station with several UIC codes keeps them all, joined by ";", in
        <comment>code_uic</comment> — <comment>id_gare</comment> is the stable key.
        HELP,
)]
final class PoisStationsDownloadCommand extends Command
{
    // Stable resource redirect, always resolves to the publisher's current version.
    private const string STATIONS_CSV_URL = 'https://www.data.gouv.fr/api/1/datasets/r/cbacca02-6925-4a46-aab6-7194debbb9b7';

    private const array CSV_COLUMNS = ['id_gare', 'name', 'lat', 'lon', 'code_commune', 'code_uic'];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CsvReader $csvReader,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $targetPath = \dirname(__DIR__, 2).'/resources/geo/gares-voyageurs.csv';

        $source = tempnam(sys_get_temp_dir(), 'gares-');
        if (false === $source) {
            throw new \RuntimeException('Cannot create a temporary file.');
        }

        try {
            $io->writeln('Downloading <info>gares-voyageurs.csv</info>...');
            file_put_contents($source, $this->httpClient->request('GET', self::STATIONS_CSV_URL)->getContent());
            $count = $this->writeStations($source, $targetPath);
        } catch (ExceptionInterface $e) {
            $io->error(\sprintf('Download failed: %s', $e->getMessage()));

            return Command::FAILURE;
        } finally {
            unlink($source);
        }

        $io->success(\sprintf('%d stations written. Review with `git diff resources/geo/` before committing.', $count));

        return Command::SUCCESS;
    }

    private function writeStations(string $sourcePath, string $targetPath): int
    {
        $out = fopen($targetPath, 'w') ?: throw new \RuntimeException("Cannot write {$targetPath}");
        fputcsv($out, self::CSV_COLUMNS, escape: '\\');

        $count = 0;
        foreach ($this->csvReader->read($sourcePath, ';') as $row) {
            // The file starts with a UTF-8 BOM, which ends up glued to the first column name.
            $row = array_combine(array_map(static fn (string $name): string => ltrim($name, "\u{feff}"), array_keys($row)), $row);

            [$lat, $lon] = array_map(trim(...), explode(',', $row['Position géographique']) + [1 => '']);
            if (!is_numeric($lat) || !is_numeric($lon)) {
                throw new \RuntimeException(\sprintf('Unexpected position "%s" for station "%s".', $row['Position géographique'], $row['Nom_Gare']));
            }

            fputcsv($out, [$row['Id_Gare'], $row['Nom_Gare'], $lat, $lon, $row['Code commune'], $row['Code_UIC']], escape: '\\');
            ++$count;
        }

        fclose($out);

        return $count;
    }
}
