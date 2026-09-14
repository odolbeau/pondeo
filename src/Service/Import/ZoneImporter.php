<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Entity\Commune;
use App\Entity\Departement;
use App\Entity\Epci;
use App\Entity\Region;
use App\Enum\EpciType;
use App\Repository\CommuneRepository;
use App\Repository\DepartementRepository;
use App\Repository\EpciRepository;
use App\Repository\RegionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Reads the vendored files under `resources/geo/` (see
 * ZonesDownloadCommand) and upserts them into Region/Departement/Epci/
 * Commune. Idempotent: safe to re-run (dev resets, prod re-syncs after an
 * annual data refresh) — never creates duplicates, matches by natural code.
 */
final readonly class ZoneImporter
{
    private const int BATCH_SIZE = 500;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private RegionRepository $regionRepository,
        private DepartementRepository $departementRepository,
        private EpciRepository $epciRepository,
        private CommuneRepository $communeRepository,
    ) {
    }

    public function importRegions(string $geojsonPath): int
    {
        $count = 0;
        foreach ($this->readFeatures($geojsonPath) as [$code, $name, $geometry]) {
            $region = $this->regionRepository->findOneBy(['inseeCode' => $code]);
            if (null === $region) {
                $region = new Region($code, $name);
                $this->entityManager->persist($region);
            } else {
                $region->setName($name);
            }

            $this->entityManager->flush();
            if (null !== $geometry) {
                $this->updateBoundary('region', $region->getId(), $geometry);
            }

            ++$count;
        }

        $this->entityManager->clear();

        return $count;
    }

    /**
     * @param array<string, string> $regionCodeByDepartementCode from {@see buildDepartementRegionMap()}
     */
    public function importDepartements(string $geojsonPath, array $regionCodeByDepartementCode): int
    {
        $count = 0;
        foreach ($this->readFeatures($geojsonPath) as [$code, $name, $geometry]) {
            // france-geojson's départements file has no région code in its
            // properties — resolved from the commune↔EPCI CSV instead
            // (which does carry both dep_code and reg_code per row).
            $regionCode = $regionCodeByDepartementCode[$code]
                ?? throw new \RuntimeException(\sprintf('No région mapped for département %s (%s).', $code, $name));
            $region = $this->regionRepository->findOneBy(['inseeCode' => $regionCode])
                ?? throw new \RuntimeException(\sprintf('Région %s not found — run importRegions() first.', $regionCode));

            $departement = $this->departementRepository->findOneBy(['inseeCode' => $code]);
            if (null === $departement) {
                $departement = new Departement($code, $name, $region);
                $this->entityManager->persist($departement);
            } else {
                $departement->setName($name);
            }

            $this->entityManager->flush();
            if (null !== $geometry) {
                $this->updateBoundary('departement', $departement->getId(), $geometry);
            }

            ++$count;
        }

        $this->entityManager->clear();

        return $count;
    }

    public function importCommunes(string $geojsonPath): int
    {
        /** @var array<string, Uuid> $departementIdsByCode */
        $departementIdsByCode = [];
        /** @var array<string, Uuid> $regionIdsByDepartementCode */
        $regionIdsByDepartementCode = [];
        foreach ($this->departementRepository->findAll() as $departement) {
            $departementIdsByCode[$departement->getInseeCode()] = $departement->getId();
            $regionIdsByDepartementCode[$departement->getInseeCode()] = $departement->getRegion()->getId();
        }

        $this->entityManager->clear();

        // Preloaded once (like the two maps above) rather than one
        // findOneBy() query per commune — the dominant cost otherwise for
        // 35k rows. Existing communes are updated via raw SQL below (name
        // change is a rare edge case, see plan), never re-fetched as
        // entities, so this stays a single query regardless of re-runs.
        /** @var array<string, Uuid> $existingCommuneIdsByCode */
        $existingCommuneIdsByCode = [];
        foreach ($this->communeRepository->findAll() as $commune) {
            $existingCommuneIdsByCode[$commune->getInseeCode()] = $commune->getId();
        }

        $this->entityManager->clear();

        $connection = $this->entityManager->getConnection();
        $count = 0;
        $pendingBoundaries = [];
        foreach ($this->readFeatures($geojsonPath) as [$code, $name, $geometry]) {
            $departementCode = substr($code, 0, 2);
            $departementId = $departementIdsByCode[$departementCode] ?? null;
            $regionId = $regionIdsByDepartementCode[$departementCode] ?? null;
            if (null === $departementId || null === $regionId) {
                continue; // Not in métropole scope (or genuinely unmapped) — skip.
            }

            $existingId = $existingCommuneIdsByCode[$code] ?? null;
            if (null === $existingId) {
                // Managed references (no DB hit) — never dereferenced, so
                // safe to use as relation targets even across the periodic
                // clear() below (see Commune's constructor docblock).
                $departementRef = $this->entityManager->getReference(Departement::class, $departementId)
                    ?? throw new \LogicException("No reference for département id {$departementId}.");
                $regionRef = $this->entityManager->getReference(Region::class, $regionId)
                    ?? throw new \LogicException("No reference for région id {$regionId}.");
                $commune = new Commune($code, $name, $departementRef, $regionRef);
                $this->entityManager->persist($commune);
                $communeId = $commune->getId();
            } else {
                $connection->executeStatement(
                    'UPDATE commune SET name = :name WHERE id = :id',
                    ['name' => $name, 'id' => $existingId->toRfc4122()],
                );
                $communeId = $existingId;
            }

            if (null !== $geometry) {
                $pendingBoundaries[] = [$communeId, $geometry];
            }

            ++$count;

            if (0 === $count % self::BATCH_SIZE) {
                $this->flushBoundaries($pendingBoundaries);
            }
        }

        $this->flushBoundaries($pendingBoundaries);

        return $count;
    }

    /**
     * @return array<string, string> région code indexed by département code
     */
    public function buildDepartementRegionMap(string $csvPath): array
    {
        $map = [];
        foreach ($this->readCsv($csvPath) as $row) {
            $map[$row['dep_code']] ??= $row['reg_code'];
        }

        return $map;
    }

    public function importEpciMembership(string $csvPath): int
    {
        /** @var array<string, Uuid> $communeIdsByCode */
        $communeIdsByCode = [];
        foreach ($this->communeRepository->findAll() as $commune) {
            $communeIdsByCode[$commune->getInseeCode()] = $commune->getId();
        }

        $this->entityManager->clear();

        /** @var array<string, Uuid> $epciIdsBySiren */
        $epciIdsBySiren = [];
        foreach ($this->epciRepository->findAll() as $epci) {
            $epciIdsBySiren[$epci->getSirenCode()] = $epci->getId();
        }

        $this->entityManager->clear();

        $connection = $this->entityManager->getConnection();
        $count = 0;

        foreach ($this->readCsv($csvPath) as $row) {
            if ('COM' !== $row['typecom']) {
                continue; // Skip communes déléguées/arrondissements (Paris/Lyon/Marseille) — out of v1 scope.
            }

            $communeId = $communeIdsByCode[$row['code_insee']] ?? null;
            $epciCode = $row['epci_code'];
            if (null === $communeId || '' === $epciCode) {
                continue; // Commune not imported, or genuinely EPCI-less at this snapshot.
            }

            $epciId = $epciIdsBySiren[$epciCode] ?? null;
            if (null === $epciId) {
                $epci = new Epci($epciCode, $row['epci_nom'], $this->inferEpciType($row['epci_nom']));
                $this->entityManager->persist($epci);
                $this->entityManager->flush();
                $epciId = $epci->getId();
                $epciIdsBySiren[$epciCode] = $epciId;
            } else {
                // Keep name/type in sync with the source on re-runs.
                $connection->executeStatement(
                    'UPDATE epci SET name = :name, epci_type = :type WHERE id = :id',
                    [
                        'name' => $row['epci_nom'],
                        'type' => $this->inferEpciType($row['epci_nom'])->value,
                        'id' => $epciId->toRfc4122(),
                    ],
                );
            }

            $connection->executeStatement(
                'UPDATE commune SET epci_id = :epciId WHERE id = :communeId',
                ['epciId' => $epciId->toRfc4122(), 'communeId' => $communeId->toRfc4122()],
            );
            ++$count;
        }

        return $count;
    }

    public function computeEpciBoundaries(): void
    {
        $this->entityManager->getConnection()->executeStatement(
            <<<'SQL'
                UPDATE epci
                SET boundary = sub.geom
                FROM (
                    SELECT epci_id, ST_Multi(ST_Union(boundary)) AS geom
                    FROM commune
                    WHERE epci_id IS NOT NULL AND boundary IS NOT NULL
                    GROUP BY epci_id
                ) AS sub
                WHERE epci.id = sub.epci_id
                SQL,
        );
    }

    /**
     * @param list<array{0: Uuid, 1: array<string, mixed>}> $pendingBoundaries
     */
    private function flushBoundaries(array &$pendingBoundaries): void
    {
        $this->entityManager->flush();
        $this->entityManager->clear();
        foreach ($pendingBoundaries as [$id, $geometry]) {
            $this->updateBoundary('commune', $id, $geometry);
        }

        $pendingBoundaries = [];
    }

    /**
     * @param array<string, mixed> $geometry GeoJSON geometry (Polygon or MultiPolygon)
     */
    private function updateBoundary(string $table, Uuid $id, array $geometry): void
    {
        $this->entityManager->getConnection()->executeStatement(
            \sprintf('UPDATE %s SET boundary = ST_Multi(ST_GeomFromGeoJSON(:geojson)) WHERE id = :id', $table),
            ['geojson' => json_encode($geometry, \JSON_THROW_ON_ERROR), 'id' => $id->toRfc4122()],
        );
    }

    /**
     * @return iterable<array{0: string, 1: string, 2: ?array<string, mixed>}> [code, nom, geometry]
     */
    private function readFeatures(string $geojsonPath): iterable
    {
        $content = file_get_contents($geojsonPath) ?: throw new \RuntimeException("Cannot read {$geojsonPath}");
        /** @var array{features: list<array{properties: array{code: string, nom: string}, geometry: ?array<string, mixed>}>} $data */
        $data = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);

        foreach ($data['features'] as $feature) {
            yield [$feature['properties']['code'], $feature['properties']['nom'], $feature['geometry']];
        }
    }

    /**
     * @return iterable<array{code_insee: string, typecom: string, dep_code: string, reg_code: string, epci_code: string, epci_nom: string}>
     */
    private function readCsv(string $csvPath): iterable
    {
        $handle = fopen($csvPath, 'r') ?: throw new \RuntimeException("Cannot read {$csvPath}");
        $header = array_map(strval(...), fgetcsv($handle, escape: '\\') ?: throw new \RuntimeException("Empty CSV: {$csvPath}"));

        while (false !== ($row = fgetcsv($handle, escape: '\\'))) {
            /** @var array<string, string> $associative */
            $associative = array_combine($header, array_map(strval(...), $row));
            yield [
                'code_insee' => $associative['code_insee'],
                'typecom' => $associative['typecom'],
                'dep_code' => $associative['dep_code'],
                'reg_code' => $associative['reg_code'],
                'epci_code' => $associative['epci_code'],
                'epci_nom' => $associative['epci_nom'],
            ];
        }

        fclose($handle);
    }

    /**
     * No legal-nature column is available in the source CSV — approximated
     * from the EPCI's name (see plan: imperfect but reasonable for v1,
     * "Communauté de communes" being the fallback since it's the most
     * common category).
     */
    private function inferEpciType(string $epciNom): EpciType
    {
        // The source data (data.gouv.fr) names EPCI with the standard
        // "CA "/"CC "/"CU " abbreviated prefix (e.g. "CA Laval
        // Agglomération") — métropoles don't follow that convention (e.g.
        // "Nantes Métropole", "Métropole du Grand Paris"), hence the
        // separate substring check. Verified against actual imported data.
        return match (true) {
            str_contains($epciNom, 'Métropole') => EpciType::Metropole,
            str_starts_with($epciNom, 'CU ') => EpciType::CommunauteUrbaine,
            str_starts_with($epciNom, 'CA ') => EpciType::CommunauteDAgglomeration,
            default => EpciType::CommunauteDeCommunes,
        };
    }
}
