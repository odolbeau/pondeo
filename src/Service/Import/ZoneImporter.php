<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Entity\Commune;
use App\Entity\UniteUrbaine;
use App\Enum\Departement;
use App\Repository\CommuneRepository;
use App\Repository\UniteUrbaineRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reads the vendored files under `resources/geo/` (see
 * ZonesDownloadCommand) and upserts them into UniteUrbaine/Commune.
 * Idempotent: safe to re-run (dev resets, prod re-syncs after a data
 * refresh) — never creates duplicates, matches by natural code, and drops
 * what the source no longer lists.
 */
final readonly class ZoneImporter
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UniteUrbaineRepository $uniteUrbaineRepository,
        private CommuneRepository $communeRepository,
        private CsvReader $csvReader,
    ) {
    }

    /**
     * @return array{unitesUrbaines: int, communes: int}
     */
    public function importUnitesUrbaines(string $csvPath): array
    {
        /** @var array<string, list<array<string, string>>> $rowsByUniteUrbaineCode */
        $rowsByUniteUrbaineCode = [];
        foreach ($this->csvReader->read($csvPath) as $row) {
            $rowsByUniteUrbaineCode[$row['code_unite_urbaine']][] = $row;
        }

        /** @var array<string, UniteUrbaine> $unitesUrbaines */
        $unitesUrbaines = [];
        foreach ($this->uniteUrbaineRepository->findAll() as $uniteUrbaine) {
            $unitesUrbaines[$uniteUrbaine->getCode()] = $uniteUrbaine;
        }

        /** @var array<string, Commune> $communes */
        $communes = [];
        foreach ($this->communeRepository->findAll() as $commune) {
            $communes[$commune->getCode()] = $commune;
        }

        $communeCount = 0;
        foreach ($rowsByUniteUrbaineCode as $code => $rows) {
            $code = (string) $code; // PHP turns all-digit array keys into ints.
            $uniteUrbaine = $unitesUrbaines[$code] ?? null;
            if (null === $uniteUrbaine) {
                $uniteUrbaine = new UniteUrbaine($code, $rows[0]['nom_unite_urbaine']);
                $this->entityManager->persist($uniteUrbaine);
                $unitesUrbaines[$code] = $uniteUrbaine;
            } else {
                $uniteUrbaine->setName($rows[0]['nom_unite_urbaine']);
            }

            $mostPopulousRow = $rows[0];
            foreach ($rows as $row) {
                if ((int) $row['population'] > (int) $mostPopulousRow['population']) {
                    $mostPopulousRow = $row;
                }

                $commune = $communes[$row['code_insee']] ?? null;
                if (null === $commune) {
                    $commune = new Commune($row['code_insee'], $row['nom_standard'], Departement::from($row['dep_code']), $uniteUrbaine);
                    $this->entityManager->persist($commune);
                } else {
                    $commune->setName($row['nom_standard']);
                    $commune->setUniteUrbaine($uniteUrbaine);
                }

                unset($communes[$row['code_insee']]);
                ++$communeCount;
            }

            $uniteUrbaine->setDepartement(Departement::from($mostPopulousRow['dep_code']));
            unset($unitesUrbaines[$code]);
        }

        // What is left was not in the source this time: communes first, they
        // reference their unité urbaine.
        foreach ($communes as $staleCommune) {
            $this->entityManager->remove($staleCommune);
        }

        foreach ($unitesUrbaines as $staleUniteUrbaine) {
            $this->entityManager->remove($staleUniteUrbaine);
        }

        $this->entityManager->flush();
        $this->entityManager->clear();

        return ['unitesUrbaines' => \count($rowsByUniteUrbaineCode), 'communes' => $communeCount];
    }

    /**
     * @return int number of communes whose boundary was set
     */
    public function importCommuneBoundaries(string $geojsonPath): int
    {
        $content = file_get_contents($geojsonPath) ?: throw new \RuntimeException("Cannot read {$geojsonPath}");
        /** @var array{features: list<array{properties: array{code: string}, geometry: ?array<string, mixed>}>} $data */
        $data = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);

        $connection = $this->entityManager->getConnection();
        $count = 0;
        foreach ($data['features'] as $feature) {
            if (null === $feature['geometry']) {
                continue;
            }

            $count += (int) $connection->executeStatement(
                'UPDATE commune SET boundary = ST_Multi(ST_GeomFromGeoJSON(:geojson)) WHERE insee_code = :code',
                ['geojson' => json_encode($feature['geometry'], \JSON_THROW_ON_ERROR), 'code' => $feature['properties']['code']],
            );
        }

        return $count;
    }

    public function computeUniteUrbaineBoundaries(): void
    {
        $this->entityManager->getConnection()->executeStatement(
            <<<'SQL'
                UPDATE unite_urbaine
                SET boundary = sub.geom
                FROM (
                    SELECT unite_urbaine_id, ST_Multi(ST_Union(boundary)) AS geom
                    FROM commune
                    WHERE boundary IS NOT NULL
                    GROUP BY unite_urbaine_id
                ) AS sub
                WHERE unite_urbaine.id = sub.unite_urbaine_id
                SQL,
        );
    }
}
