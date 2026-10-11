<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Factory\UniteUrbaineFactory;
use App\Service\Import\IncomeImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class IncomeImporterTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function testImportStoresTheFiguresOfEachUniteUrbaine(): void
    {
        UniteUrbaineFactory::createOne(['inseeCode' => '56501']);
        UniteUrbaineFactory::createOne(['inseeCode' => '44701']);

        // 99999 is not one of ours, and 44701 has no poverty rate (statistical secrecy).
        $csvPath = $this->writeCsv(<<<'CSV'
            code_unite_urbaine,year,measure,value
            56501,2023,median,25099.6
            56501,2023,poverty,10.5
            44701,2023,median,22000
            99999,2023,median,99000
            CSV);

        $importer = self::getContainer()->get(IncomeImporter::class);
        \assert($importer instanceof IncomeImporter);

        self::assertSame(3, $importer->import($csvPath));

        self::assertSame(25100, $this->integer('56501', 'niveau_vie_median'));
        self::assertSame(10.5, $this->decimal('56501', 'taux_pauvrete'));
        self::assertSame(22000, $this->integer('44701', 'niveau_vie_median'));
        self::assertNull($this->decimal('44701', 'taux_pauvrete'));

        // Idempotent: a second run updates in place.
        $importer->import($csvPath);
        self::assertSame(25100, $this->integer('56501', 'niveau_vie_median'));

        unlink($csvPath);
    }

    private function writeCsv(string $content): string
    {
        $csvPath = tempnam(sys_get_temp_dir(), 'income-importer-test-');
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
