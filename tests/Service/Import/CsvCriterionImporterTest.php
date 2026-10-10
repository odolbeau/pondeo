<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Enum\CriterionValueType;
use App\Factory\CommuneFactory;
use App\Factory\UniteUrbaineFactory;
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

    public function testImportSumsCommunesPerUniteUrbaineThenUpdatesInPlace(): void
    {
        $vannesUnit = UniteUrbaineFactory::createOne(['inseeCode' => '56501']);
        CommuneFactory::createOne(['inseeCode' => '56260', 'uniteUrbaine' => $vannesUnit]);
        CommuneFactory::createOne(['inseeCode' => '56206', 'uniteUrbaine' => $vannesUnit]);
        $otherUnit = UniteUrbaineFactory::createOne(['inseeCode' => '44701']);
        CommuneFactory::createOne(['inseeCode' => '44109', 'uniteUrbaine' => $otherUnit]);

        $spec = new CriterionCsvSpec(
            code: 'test_metric',
            label: 'Test metric',
            valueType: CriterionValueType::Integer,
            unit: 'unit',
            communeCodeColumn: 'code_insee',
            periodColumn: 'year',
            valueColumn: 'value',
        );

        $csvPath = tempnam(sys_get_temp_dir(), 'csv-criterion-importer-test-');
        \assert(false !== $csvPath);

        // 99999 is a commune outside every unité urbaine: skipped.
        file_put_contents($csvPath, "code_insee,year,value\n56260,2020,100\n56206,2020,20\n56260,2021,110\n56206,2021,21\n44109,2020,500\n99999,2020,7\n");

        $importer = self::getContainer()->get(CsvCriterionImporter::class);
        \assert($importer instanceof CsvCriterionImporter);

        self::assertSame(3, $importer->import($spec, $csvPath));
        self::assertSame([2020 => 120, 2021 => 131], $this->valuesByPeriod('56501', $spec->code));
        self::assertSame([2020 => 500], $this->valuesByPeriod('44701', $spec->code));

        // Re-import with an updated value — must update in place, never
        // duplicate (idempotent upsert).
        file_put_contents($csvPath, "code_insee,year,value\n56260,2020,999\n56206,2020,1\n56260,2021,110\n56206,2021,21\n44109,2020,500\n");

        self::assertSame(3, $importer->import($spec, $csvPath));
        self::assertSame([2020 => 1_000, 2021 => 131], $this->valuesByPeriod('56501', $spec->code));

        unlink($csvPath);
    }

    public function testImportStoresTheGroupAndKeepsAnExistingCriterionInStep(): void
    {
        $unit = UniteUrbaineFactory::createOne(['inseeCode' => '56501']);
        CommuneFactory::createOne(['inseeCode' => '56260', 'uniteUrbaine' => $unit]);

        $csvPath = tempnam(sys_get_temp_dir(), 'csv-criterion-importer-test-');
        \assert(false !== $csvPath);
        file_put_contents($csvPath, "code_insee,year,value\n56260,2020,100\n");

        $importer = self::getContainer()->get(CsvCriterionImporter::class);
        \assert($importer instanceof CsvCriterionImporter);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);

        $specFor = static fn (?string $groupLabel): CriterionCsvSpec => new CriterionCsvSpec(
            code: 'test_metric',
            label: 'Test metric',
            valueType: CriterionValueType::Integer,
            unit: null,
            communeCodeColumn: 'code_insee',
            periodColumn: 'year',
            valueColumn: 'value',
            groupLabel: $groupLabel,
        );
        $storedGroup = static fn (): mixed => $entityManager->getConnection()->fetchOne("SELECT group_label FROM criterion WHERE code = 'test_metric'");

        $importer->import($specFor('Démographie'), $csvPath);
        self::assertSame('Démographie', $storedGroup());

        $importer->import($specFor('Économie'), $csvPath);
        self::assertSame('Économie', $storedGroup());

        unlink($csvPath);
    }

    public function testImportRejectsNonAdditiveCriterion(): void
    {
        $spec = new CriterionCsvSpec(
            code: 'test_label',
            label: 'Test label',
            valueType: CriterionValueType::Text,
            unit: null,
            communeCodeColumn: 'code_insee',
            periodColumn: 'year',
            valueColumn: 'value',
        );

        $importer = self::getContainer()->get(CsvCriterionImporter::class);
        \assert($importer instanceof CsvCriterionImporter);

        $this->expectException(\LogicException::class);

        $importer->import($spec, '/nonexistent.csv');
    }

    /**
     * Asserted via the DBAL connection directly, not the ORM: hydrating
     * CriterionValue's `criterion` association after the importer clears
     * the entity manager hits a pre-existing Doctrine ORM bug unrelated to
     * this importer (readonly UuidIdTrait::$id vs. native lazy ghost
     * objects) — flagged separately rather than worked around here.
     *
     * @return array<int, int>
     */
    private function valuesByPeriod(string $uniteUrbaineCode, string $criterionCode): array
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);

        /** @var list<array{period: int, value_integer: int}> $rows */
        $rows = $entityManager->getConnection()->fetchAllAssociative(
            <<<'SQL'
                SELECT cv.period, cv.value_integer
                FROM criterion_value cv
                INNER JOIN unite_urbaine uu ON uu.id = cv.unite_urbaine_id
                INNER JOIN criterion ON criterion.id = cv.criterion_id
                WHERE uu.insee_code = :code AND criterion.code = :criterionCode
                SQL,
            ['code' => $uniteUrbaineCode, 'criterionCode' => $criterionCode],
        );

        $byPeriod = [];
        foreach ($rows as $row) {
            $byPeriod[$row['period']] = $row['value_integer'];
        }

        return $byPeriod;
    }
}
