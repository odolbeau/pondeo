<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Enum\AssociationFamily;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Refreshes `resources/geo/associations-rna.csv` from the Répertoire national
 * des associations (Ministry of the Interior, on data.gouv.fr): the number of
 * associations per commune and category, counted from the latest monthly
 * WALDEC extract (see `docs/criteria-associations.md`).
 */
final readonly class AssociationsSourceDownloader
{
    private const string DATASET_URL = 'https://www.data.gouv.fr/api/1/datasets/58e53811c751df03df38f42d/';

    // An association whose last declaration is older than this is not active.
    public const int ACTIVE_YEARS = 10;

    // The registry of these departments is the local one kept by the courts: the RNA barely covers them.
    private const array LOCAL_LAW_DEPARTMENTS = ['57', '67', '68'];

    public function __construct(private HttpClientInterface $httpClient)
    {
    }

    /**
     * @param \Closure(string): void $progress called with a line per step
     *
     * @throws ExceptionInterface when a request fails
     */
    public function download(string $targetDir, \Closure $progress): void
    {
        [$url, $year] = $this->latestExtract();

        $progress(\sprintf('Downloading %s...', $url));
        $archive = tempnam(sys_get_temp_dir(), 'rna-waldec-') ?: throw new \RuntimeException('Cannot create a temporary file.');

        try {
            $this->fetch($url, $archive);
            $counts = $this->count($archive, $year, $progress);
        } finally {
            @unlink($archive);
        }

        $this->write($targetDir.'/associations-rna.csv', $counts, $year);
    }

    /**
     * @return array{string, int} the URL of the latest WALDEC extract and its year
     */
    private function latestExtract(): array
    {
        /** @var array{resources: list<array{title: string, url: string}>} $dataset */
        $dataset = $this->httpClient->request('GET', self::DATASET_URL)->toArray();

        $latest = null;
        foreach ($dataset['resources'] as $resource) {
            if (1 !== preg_match('/^rna_waldec_(\d{8})\.zip$/', $resource['title'], $matches)) {
                continue;
            }

            if (null === $latest || $matches[1] > $latest[0]) {
                $latest = [$matches[1], $resource['url']];
            }
        }

        if (null === $latest) {
            throw new \RuntimeException('No WALDEC extract found in the RNA dataset.');
        }

        return [$latest[1], (int) substr($latest[0], 0, 4)];
    }

    private function fetch(string $url, string $path): void
    {
        $handle = fopen($path, 'w') ?: throw new \RuntimeException("Cannot write {$path}");

        $response = $this->httpClient->request('GET', $url);
        foreach ($this->httpClient->stream($response) as $chunk) {
            fwrite($handle, $chunk->getContent());
        }

        fclose($handle);
    }

    /**
     * @param \Closure(string): void $progress
     *
     * @return array<string, array<string, int>> counts per commune code and category
     */
    private function count(string $archive, int $year, \Closure $progress): array
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($archive)) {
            throw new \RuntimeException('The RNA archive cannot be opened.');
        }

        $counts = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = (string) $zip->getNameIndex($i);
            if (1 !== preg_match('/_dpt_(\w+)\.csv$/', $name, $matches) || \in_array($matches[1], self::LOCAL_LAW_DEPARTMENTS, true)) {
                continue;
            }

            $progress(\sprintf('Counting %s...', $name));
            $handle = $zip->getStream($name);
            if (false === $handle) {
                throw new \RuntimeException("Cannot read {$name} from the RNA archive.");
            }

            $this->countFile($handle, $year, $counts);
            fclose($handle);
        }

        $zip->close();

        return $counts;
    }

    /**
     * @param resource                          $handle
     * @param array<string, array<string, int>> $counts
     */
    private function countFile($handle, int $year, array &$counts): void
    {
        $header = fgetcsv($handle, separator: ';', escape: '');
        if (!\is_array($header)) {
            return;
        }

        $header[0] = ltrim((string) $header[0], "\u{FEFF}");
        $columns = array_flip(array_map(strval(...), $header));
        foreach (['position', 'adrs_codeinsee', 'objet_social1', 'date_decla'] as $column) {
            if (!isset($columns[$column])) {
                throw new \RuntimeException("Column {$column} missing from the RNA extract.");
            }
        }

        $oldestActiveYear = $year - self::ACTIVE_YEARS + 1;

        while (false !== ($row = fgetcsv($handle, separator: ';', escape: ''))) {
            if ('A' !== ($row[$columns['position']] ?? null)) {
                continue;
            }

            $commune = $row[$columns['adrs_codeinsee']] ?? '';
            // Some seats carry a placeholder code ("0") instead of a commune.
            if (5 !== \strlen($commune)) {
                continue;
            }

            $counts[$commune][AssociationsImporter::ALL] = ($counts[$commune][AssociationsImporter::ALL] ?? 0) + 1;

            // For an association never amended, the last declaration is its creation.
            if ((int) substr($row[$columns['date_decla']] ?? '', 0, 4) < $oldestActiveYear) {
                continue;
            }

            $counts[$commune][AssociationsImporter::ACTIVE] = ($counts[$commune][AssociationsImporter::ACTIVE] ?? 0) + 1;

            $family = AssociationFamily::fromObjectCode($row[$columns['objet_social1']] ?? '');
            if (null !== $family) {
                $counts[$commune][$family->value] = ($counts[$commune][$family->value] ?? 0) + 1;
            }
        }
    }

    /**
     * @param array<string, array<string, int>> $counts
     */
    private function write(string $path, array $counts, int $year): void
    {
        ksort($counts);

        $handle = fopen($path, 'w') ?: throw new \RuntimeException("Cannot write {$path}");
        fputcsv($handle, ['code_insee', 'year', 'category', 'count'], escape: '\\');

        foreach ($counts as $commune => $categories) {
            ksort($categories);
            foreach ($categories as $category => $count) {
                fputcsv($handle, [$commune, $year, $category, $count], escape: '\\');
            }
        }

        fclose($handle);
    }
}
