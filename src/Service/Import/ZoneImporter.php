<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Entity\Commune;
use App\Entity\UniteUrbaine;
use App\Enum\AttractionCategory;
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
     * Sets each unité urbaine's aire d'attraction from its most populous
     * commune (the ville-centre). A unit whose commune the zoning doesn't
     * list keeps no category, so it is never taken for a satellite.
     *
     * @return int number of unités urbaines given a category
     */
    public function importAttractionAreas(string $unitesUrbainesCsvPath, string $attractionAreasCsvPath): int
    {
        /** @var array<string, array<string, string>> $attractionByCommuneCode */
        $attractionByCommuneCode = [];
        foreach ($this->csvReader->read($attractionAreasCsvPath) as $row) {
            $attractionByCommuneCode[$row['code_insee']] = $row;
        }

        /** @var array<string, array<string, string>> $mostPopulousRows */
        $mostPopulousRows = [];
        foreach ($this->csvReader->read($unitesUrbainesCsvPath) as $row) {
            $code = $row['code_unite_urbaine'];
            if (!isset($mostPopulousRows[$code]) || (int) $row['population'] > (int) $mostPopulousRows[$code]['population']) {
                $mostPopulousRows[$code] = $row;
            }
        }

        $count = 0;
        foreach ($this->uniteUrbaineRepository->findAll() as $uniteUrbaine) {
            $communeCode = ($mostPopulousRows[$uniteUrbaine->getCode()] ?? null)['code_insee'] ?? null;
            $attraction = null === $communeCode ? null : ($attractionByCommuneCode[$communeCode] ?? null);
            $category = null === $attraction ? null : AttractionCategory::tryFrom($attraction['categorie']);

            if (null === $attraction || null === $category) {
                $uniteUrbaine->setAttraction(null, null, null);

                continue;
            }

            // "000" is INSEE's pseudo-area for communes outside any aire d'attraction.
            $isInArea = AttractionCategory::HorsAttraction !== $category;
            $uniteUrbaine->setAttraction($category, $isInArea ? $attraction['code_aav'] : null, $isInArea ? $attraction['nom_aav'] : null);
            ++$count;
        }

        $this->entityManager->flush();
        $this->entityManager->clear();

        return $count;
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
