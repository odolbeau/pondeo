<?php

declare(strict_types=1);

namespace App\Service\Import;

use Dom\HTMLDocument;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Refreshes `resources/geo/associations-jeux.csv` from Subverti's directory of
 * board game clubs, ludothèques and associations, used with their written
 * permission. The whole directory is in one page; only the structures located
 * in France are kept, as the urban units are French.
 */
final readonly class BoardGameAssociationSourceDownloader
{
    private const string DIRECTORY_URL = 'https://subverti.com/fr/maps/associations/';

    private const array CSV_COLUMNS = ['id', 'name', 'type', 'city', 'department', 'lat', 'lon', 'url'];

    public function __construct(private HttpClientInterface $httpClient)
    {
    }

    /**
     * @param \Closure(string): void $progress
     *
     * @throws ExceptionInterface when the download fails
     */
    public function download(string $targetDir, \Closure $progress): void
    {
        $progress('Downloading the Subverti directory...');
        $html = $this->httpClient->request('GET', self::DIRECTORY_URL)->getContent();

        $rows = $this->parse($html);
        if ([] === $rows) {
            throw new \RuntimeException('No structure found: the page layout probably changed.');
        }

        $out = fopen($targetDir.'/associations-jeux.csv', 'w') ?: throw new \RuntimeException('Cannot write associations-jeux.csv');
        fputcsv($out, self::CSV_COLUMNS, escape: '\\');
        foreach ($rows as $row) {
            fputcsv($out, $row, escape: '\\');
        }

        fclose($out);

        $progress(\sprintf('  %d structures in France', \count($rows)));
    }

    /**
     * @return list<array{string, string, string, string, string, string, string, string}>
     */
    private function parse(string $html): array
    {
        $rows = [];
        foreach (HTMLDocument::createFromString($html, \LIBXML_NOERROR)->querySelectorAll('li.fest__item') as $item) {
            if ('FR' !== $item->getAttribute('data-country')) {
                continue;
            }

            $latitude = trim((string) $item->getAttribute('data-latit'));
            $longitude = trim((string) $item->getAttribute('data-longit'));
            $link = $item->querySelector('a.fest__link');
            if (!is_numeric($latitude) || !is_numeric($longitude) || null === $link) {
                continue;
            }

            // "../../../fr/maps/associations/<slug>/"
            $slug = basename(rtrim((string) $link->getAttribute('href'), '/'));

            $rows[$slug] = [
                $slug,
                trim((string) $item->getAttribute('data-name')),
                (string) $item->getAttribute('data-group'),
                trim((string) $item->getAttribute('data-city')),
                (string) $item->getAttribute('data-dep'),
                $latitude,
                $longitude,
                self::DIRECTORY_URL.$slug.'/',
            ];
        }

        ksort($rows);

        return array_values($rows);
    }
}
