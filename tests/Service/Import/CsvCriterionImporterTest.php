<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Enum\CriterionValueType;
use App\Enum\ZoneType;
use App\Factory\CommuneFactory;
use App\Service\Import\CriterionCsvSpec;
use App\Service\Import\CsvCriterionImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class CsvCriterionImporterTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function testImportCreatesThenUpdatesValues(): void
    {
        $commune = CommuneFactory::createOne(['inseeCode' => '91477']);

        $spec = new CriterionCsvSpec(
            code: 'test_metric',
            label: 'Test metric',
            valueType: CriterionValueType::Integer,
            zoneType: ZoneType::Commune,
            unit: 'unit',
            zoneCodeColumn: 'code_insee',
            periodColumn: 'year',
            valueColumn: 'value',
        );

        $csvPath = tempnam(sys_get_temp_dir(), 'csv-criterion-importer-test-');
        \assert(false !== $csvPath);

        file_put_contents($csvPath, "code_insee,year,value\n91477,2020,100\n91477,2021,110\n");

        $importer = self::getContainer()->get(CsvCriterionImporter::class);
        \assert($importer instanceof CsvCriterionImporter);

        $count = $importer->import($spec, $csvPath);
        self::assertSame(2, $count);
        self::assertSame([2020 => 100, 2021 => 110], $this->valuesByPeriod($commune->getInseeCode(), $spec->code));

        // Re-import with an updated value for an existing period — must
        // update in place, never duplicate (idempotent upsert).
        file_put_contents($csvPath, "code_insee,year,value\n91477,2020,999\n91477,2021,110\n");

        $secondCount = $importer->import($spec, $csvPath);
        self::assertSame(2, $secondCount);
        self::assertSame([2020 => 999, 2021 => 110], $this->valuesByPeriod($commune->getInseeCode(), $spec->code));

        unlink($csvPath);
    }

    /**
     * Asserted via the DBAL connection directly, not the ORM: hydrating
     * ZoneCriterionValue's `criterion` association after this test's own
     * entity manager clears (see CsvCriterionImporter::loadZoneIdsByCode())
     * hits a pre-existing Doctrine ORM bug unrelated to this importer
     * (readonly UuidIdTrait::$id vs. native lazy ghost objects) — flagged
     * separately rather than worked around here.
     *
     * @return array<int, int>
     */
    private function valuesByPeriod(string $communeInseeCode, string $criterionCode): array
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);
        $connection = $entityManager->getConnection();

        /** @var list<array{period: int, value_integer: int}> $rows */
        $rows = $connection->fetchAllAssociative(
            <<<'SQL'
                SELECT zcv.period, zcv.value_integer
                FROM zone_criterion_value zcv
                INNER JOIN commune ON commune.id = zcv.commune_id
                INNER JOIN criterion ON criterion.id = zcv.criterion_id
                WHERE commune.insee_code = :inseeCode AND criterion.code = :criterionCode
                SQL,
            ['inseeCode' => $communeInseeCode, 'criterionCode' => $criterionCode],
        );

        $byPeriod = [];
        foreach ($rows as $row) {
            $byPeriod[$row['period']] = $row['value_integer'];
        }

        return $byPeriod;
    }
}
