<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Enum\Departement;
use App\Enum\JourneyDestination;
use App\Service\Map\UniteUrbaineMapDataProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

/**
 * CSV of the unités urbaines matching the map filters. Capped, so the export
 * cannot be used to download the whole database: the map's button is
 * disabled above the cap, and the cap is enforced here too.
 */
#[Route('/carte/export.csv', name: 'app_map_export')]
final class UniteUrbaineMapExportController extends AbstractController
{
    public const int LIMIT = 200;

    public function __construct(
        private readonly UniteUrbaineMapDataProvider $mapDataProvider,
    ) {
    }

    public function __invoke(#[MapQueryString] UniteUrbaineMapExportInput $filters): Response
    {
        $rows = $this->mapDataProvider->findExportRows($filters->toFilter(), self::LIMIT);

        if (\count($rows) > self::LIMIT) {
            return new JsonResponse(['error' => \sprintf('Trop de résultats (plus de %d) : affinez vos filtres.', self::LIMIT)], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $header = ['Code INSEE', 'Unité urbaine', 'Département', 'Population'];
        if (null !== $filters->commerceDensityMin) {
            $header[] = 'Commerces pour 1000 hab.';
        }

        if (null !== $filters->restaurantDensityMin) {
            $header[] = 'Restaurants pour 1000 hab.';
        }

        if (null !== $filters->priceMax) {
            $header[] = 'Prix au m² (€)';
        }

        foreach (array_keys($filters->trainMaxMinutes) as $destination) {
            $header[] = \sprintf('Trajet vers %s (min)', JourneyDestination::from((string) $destination)->label());
        }

        $response = new StreamedResponse(function () use ($header, $rows, $filters): void {
            $out = fopen('php://output', 'w');
            if (false === $out) {
                throw new \RuntimeException('Cannot open the output stream.');
            }

            // The BOM makes Excel read the file as UTF-8.
            fwrite($out, "\u{FEFF}");
            fputcsv($out, $header, ';', escape: '');
            foreach ($rows as $row) {
                $line = [
                    $row['code'],
                    $this->text($row['name']),
                    null === $row['departement'] ? '' : (Departement::tryFrom($row['departement'])?->label() ?? $row['departement']),
                    $row['population'],
                ];
                if (null !== $filters->commerceDensityMin) {
                    $line[] = $this->decimal($row['commerceDensity'], 2);
                }

                if (null !== $filters->restaurantDensityMin) {
                    $line[] = $this->decimal($row['restaurantDensity'], 2);
                }

                if (null !== $filters->priceMax) {
                    $line[] = $this->decimal($row['price'], 0);
                }

                foreach ($row['trainMinutes'] as $minutes) {
                    $line[] = $this->decimal($minutes, 0);
                }

                fputcsv($out, $line, ';', escape: '');
            }

            fclose($out);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            \sprintf('pondeo-export-%s.csv', date('Y-m-d')),
        ));

        return $response;
    }

    // French decimal comma, for a spreadsheet in French locale.
    private function decimal(?float $value, int $decimals): string
    {
        return null === $value ? '' : number_format($value, $decimals, ',', '');
    }

    // A cell starting with one of these is read as a formula by spreadsheets.
    private function text(string $value): string
    {
        return 1 === preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }
}
