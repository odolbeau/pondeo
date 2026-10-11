<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Factory\UniteUrbaineFactory;
use App\Service\Import\ClimateImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class ClimateImporterTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function testImportStoresTheNormalsOfEachUniteUrbaine(): void
    {
        UniteUrbaineFactory::createOne(['inseeCode' => '13701']);
        UniteUrbaineFactory::createOne(['inseeCode' => '29701']);

        $csvPath = tempnam(sys_get_temp_dir(), 'climate-importer-test-');
        \assert(false !== $csvPath);
        file_put_contents($csvPath, <<<'CSV'
            code_unite_urbaine,year,measure,value
            13701,2020,temperature_moyenne,15.4
            13701,2020,ensoleillement,2860.4
            13701,2020,jours_plus_30,45.3
            13701,2020,humidite_sol_ete,31.25
            29701,2020,temperature_moyenne,12.1
            99999,2020,temperature_moyenne,9.9
            CSV."\n");

        $importer = self::getContainer()->get(ClimateImporter::class);
        \assert($importer instanceof ClimateImporter);

        self::assertSame(5, $importer->import($csvPath));

        self::assertEqualsWithDelta(15.4, (float) $this->value('13701', 'temperature_moyenne', 'value_decimal'), 0.0001);
        self::assertSame('2860', $this->value('13701', 'ensoleillement', 'value_integer'));
        self::assertSame('45', $this->value('13701', 'jours_plus_30', 'value_integer'));
        self::assertNull($this->value('29701', 'ensoleillement', 'value_integer'));
        self::assertEqualsWithDelta(31.25, (float) $this->value('13701', 'humidite_sol_ete', 'value_decimal'), 0.0001);
        // More soil moisture is better; the heat-wave count is not.
        self::assertFalse($this->criterion('humidite_sol_ete')['rank_from_lowest']);
        self::assertTrue($this->criterion('vagues_chaleur')['rank_from_lowest']);
        self::assertSame(19, $this->criterion('humidite_sol_ete')['position']);

        $row = $this->criterion('jours_plus_30');
        self::assertSame('Climat', $row['group_label']);
        self::assertSame('Jours > 30 °C', $row['label']);
        self::assertTrue($row['rank_from_lowest']);
        self::assertFalse($this->criterion('ensoleillement')['rank_from_lowest']);
        self::assertFalse($this->criterion('jours_gel')['comparable']);
        self::assertSame(1, $this->criterion('jours_plus_30')['position']);

        // Idempotent: a second run updates in place.
        $importer->import($csvPath);
        self::assertSame('2860', $this->value('13701', 'ensoleillement', 'value_integer'));

        unlink($csvPath);
    }

    /**
     * @return array<string, mixed>
     */
    private function criterion(string $code): array
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);

        return $entityManager->getConnection()->fetchAssociative('SELECT * FROM criterion WHERE code = :code', ['code' => $code]) ?: [];
    }

    // Read through DBAL: see CsvCriterionImporterTest::valuesByPeriod().
    private function value(string $uniteUrbaineCode, string $criterionCode, string $column): ?string
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);

        $value = $entityManager->getConnection()->fetchOne(
            \sprintf(
                <<<'SQL'
                    SELECT cv.%s
                    FROM criterion_value cv
                    INNER JOIN unite_urbaine uu ON uu.id = cv.unite_urbaine_id
                    INNER JOIN criterion ON criterion.id = cv.criterion_id
                    WHERE uu.insee_code = :code AND criterion.code = :criterionCode
                    SQL,
                $column,
            ),
            ['code' => $uniteUrbaineCode, 'criterionCode' => $criterionCode],
        );

        return \is_scalar($value) && false !== $value ? (string) $value : null;
    }
}
