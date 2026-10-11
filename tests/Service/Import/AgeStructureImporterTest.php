<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Factory\CommuneFactory;
use App\Factory\UniteUrbaineFactory;
use App\Service\Import\AgeStructureImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class AgeStructureImporterTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function testImportDerivesTheShareOfEachAgeBracket(): void
    {
        $unit = UniteUrbaineFactory::createOne(['inseeCode' => '56501']);
        CommuneFactory::createOne(['inseeCode' => '56260', 'uniteUrbaine' => $unit]);
        CommuneFactory::createOne(['inseeCode' => '56206', 'uniteUrbaine' => $unit]);

        // 99999 is a commune outside every unité urbaine: skipped.
        $csvPath = $this->writeCsv(<<<'CSV'
            code_insee,year,status,count
            56260,2023,population,1000
            56260,2023,moins_15,150
            56260,2023,80_plus,50
            56206,2023,population,1000
            56206,2023,moins_15,250
            56206,2023,80_plus,150
            99999,2023,population,70
            CSV);

        $importer = self::getContainer()->get(AgeStructureImporter::class);
        \assert($importer instanceof AgeStructureImporter);

        $importer->import($csvPath);

        self::assertSame(20.0, $this->decimal('56501', 'part_moins_15_ans'));
        self::assertSame(10.0, $this->decimal('56501', 'part_80_ans_et_plus'));
        // A bracket missing from the CSV has no share.
        self::assertNull($this->decimal('56501', 'part_25_39_ans'));
        self::assertNull($this->integer('56501', 'count_population'));

        // Idempotent: a second run updates in place.
        $importer->import($csvPath);
        self::assertSame(20.0, $this->decimal('56501', 'part_moins_15_ans'));

        unlink($csvPath);
    }

    private function writeCsv(string $content): string
    {
        $csvPath = tempnam(sys_get_temp_dir(), 'age-structure-importer-test-');
        \assert(false !== $csvPath);
        file_put_contents($csvPath, $content."\n");

        return $csvPath;
    }

    private function integer(string $uniteUrbaineCode, string $criterionCode): ?int
    {
        $value = $this->column('value_integer', $uniteUrbaineCode, $criterionCode);

        return is_numeric($value) ? (int) $value : null;
    }

    private function decimal(string $uniteUrbaineCode, string $criterionCode): ?float
    {
        $value = $this->column('value_decimal', $uniteUrbaineCode, $criterionCode);

        return is_numeric($value) ? (float) $value : null;
    }

    // Read through DBAL: see CsvCriterionImporterTest::valuesByPeriod().
    private function column(string $column, string $uniteUrbaineCode, string $criterionCode): mixed
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

        return false === $value ? null : $value;
    }
}
