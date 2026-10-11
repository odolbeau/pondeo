<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Enum\Departement;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Refreshes `resources/geo/revenus-fiscaux.csv` from the DGFiP's "IRCOM" (the
 * income tax by commune, on data.gouv.fr): per commune, the number of tax
 * households and their reference taxable income, in total and by bracket of
 * income. Keeps the latest income year published, and metropolitan France only.
 */
final readonly class TaxIncomeSourceDownloader
{
    private const string DATASET_URL = 'https://www.data.gouv.fr/api/1/datasets/536998cba3a729239d20505e/';

    private const string SHEET = 'ListeCommune';

    /**
     * Brackets of reference taxable income of the source, grouped into the
     * ones kept, indexed by the label the source gives them.
     *
     * @var array<string, string>
     */
    private const array BRACKETS = [
        '0 à 10 000' => 'moins_15k',
        '10 001 à 12 000' => 'moins_15k',
        '12 001 à 15 000' => 'moins_15k',
        '15 001 à 20 000' => '15_30k',
        '20 001 à 30 000' => '15_30k',
        '30 001 à 50 000' => '30_50k',
        '50 001 à 100 000' => '50_100k',
        '+ de 100 000' => 'plus_100k',
    ];

    private const string TOTAL = 'Total';

    public function __construct(
        private HttpClientInterface $httpClient,
        private XlsxReader $xlsxReader,
    ) {
    }

    /**
     * @param \Closure(string): void $progress called with a line per step
     *
     * @throws ExceptionInterface when a download fails
     */
    public function download(string $targetDir, \Closure $progress): void
    {
        ['year' => $year, 'url' => $url] = $this->findLatestResource();
        $progress(\sprintf('Downloading the income of %d...', $year));

        $zipPath = tempnam(sys_get_temp_dir(), 'ircom-zip-');
        $xlsxPath = tempnam(sys_get_temp_dir(), 'ircom-xlsx-');
        if (false === $zipPath || false === $xlsxPath) {
            throw new \RuntimeException('Cannot create a temporary file.');
        }

        try {
            file_put_contents($zipPath, $this->httpClient->request('GET', $url)->getContent());
            $this->extractWorkbook($zipPath, $xlsxPath);

            $progress(\sprintf('  %d communes written', $this->writeCsv($xlsxPath, $year, $targetDir.'/revenus-fiscaux.csv')));
        } finally {
            unlink($zipPath);
            unlink($xlsxPath);
        }
    }

    /**
     * @return array{year: int, url: string}
     *
     * @throws ExceptionInterface
     */
    private function findLatestResource(): array
    {
        /** @var array{resources: list<array{title: string, url: string, format: string}>} $dataset */
        $dataset = $this->httpClient->request('GET', self::DATASET_URL)->toArray();

        $latest = null;
        foreach ($dataset['resources'] as $resource) {
            if ('zip' === $resource['format'] && 1 === preg_match('/revenus de (\d{4}) d[ée]clar/u', $resource['title'], $matches) && (null === $latest || (int) $matches[1] > $latest['year'])) {
                $latest = ['year' => (int) $matches[1], 'url' => $resource['url']];
            }
        }

        return $latest ?? throw new \RuntimeException('No yearly IRCOM archive found on data.gouv.fr.');
    }

    private function extractWorkbook(string $zipPath, string $xlsxPath): void
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($zipPath)) {
            throw new \RuntimeException('The IRCOM download is not a valid zip file.');
        }

        try {
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $name = (string) $zip->getNameIndex($i);
                if (1 === preg_match('/ircom_communes_complet.*\.xlsx$/', $name)) {
                    file_put_contents($xlsxPath, $zip->getFromIndex($i));

                    return;
                }
            }
        } finally {
            $zip->close();
        }

        throw new \RuntimeException('The IRCOM archive holds no communal workbook.');
    }

    /**
     * @return int number of communes written
     */
    private function writeCsv(string $xlsxPath, int $year, string $targetPath): int
    {
        /** @var array<string, array<string, float>> $communes indexed by commune code, then status */
        $communes = [];
        foreach ($this->xlsxReader->read($xlsxPath, self::SHEET) as $row) {
            $code = $this->communeCode($row[1] ?? '', $row[2] ?? '');
            $status = $this->status($row[4] ?? '');
            if (null === $code || null === $status || !is_numeric($row[5] ?? '')) {
                continue; // A header, an overseas commune, or a figure withheld by statistical secrecy.
            }

            if ('foyers' === $status) {
                $communes[$code]['rfr'] = (float) ($row[6] ?? 0);
            }

            $communes[$code][$status] = ($communes[$code][$status] ?? 0.0) + (float) $row[5];
        }

        $handle = fopen($targetPath, 'w') ?: throw new \RuntimeException("Cannot write {$targetPath}");
        fputcsv($handle, ['code_insee', 'year', 'status', 'count'], escape: '\\');
        foreach ($communes as $code => $statuses) {
            // The breakdown by bracket is withheld for the smallest communes, and sometimes in part: it is only
            // kept whole, with its own total, so that the shares of a unité urbaine rest on the same households.
            $brackets = array_intersect_key($statuses, array_flip(self::BRACKETS));
            $hasBreakdown = \count($brackets) === \count(array_unique(self::BRACKETS));
            $statuses = array_diff_key($statuses, $brackets);
            if ($hasBreakdown) {
                $statuses += $brackets + ['foyers_tranches' => array_sum($brackets)];
            }

            foreach ($statuses as $status => $count) {
                fputcsv($handle, [$code, $year, $status, round($count, 2)], escape: '\\');
            }
        }

        fclose($handle);

        return \count($communes);
    }

    /**
     * @param string $department three characters, the department code and a trailing "0" ("010", "2A0")
     */
    private function communeCode(string $department, string $commune): ?string
    {
        $department = substr($department, 0, 2);
        if (null === Departement::tryFrom($department) || 1 !== preg_match('/^\d{3}$/', $commune)) {
            return null;
        }

        return $department.$commune;
    }

    /**
     * @return string|null "foyers" for the total row, the grouped bracket otherwise
     */
    private function status(string $bracket): ?string
    {
        return self::TOTAL === $bracket ? 'foyers' : (self::BRACKETS[$bracket] ?? null);
    }
}
