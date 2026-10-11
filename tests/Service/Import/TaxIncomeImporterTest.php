<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Factory\CommuneFactory;
use App\Factory\UniteUrbaineFactory;
use App\Service\Import\TaxIncomeImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class TaxIncomeImporterTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function testImportDerivesTheMeanIncomeAndTheShareOfEachBracket(): void
    {
        $unit = UniteUrbaineFactory::createOne(['inseeCode' => '56501']);
        CommuneFactory::createOne(['inseeCode' => '56260', 'uniteUrbaine' => $unit]);
        CommuneFactory::createOne(['inseeCode' => '56206', 'uniteUrbaine' => $unit]);
        // No breakdown by bracket for this one: it counts for the mean income, not for the shares.
        CommuneFactory::createOne(['inseeCode' => '56207', 'uniteUrbaine' => $unit]);

        // 99999 is a commune outside every unité urbaine: skipped. The reference income is in thousands of euros.
        $csvPath = $this->writeCsv(<<<'CSV'
            code_insee,year,status,count
            56260,2024,foyers,600
            56260,2024,rfr,15000.5
            56260,2024,foyers_tranches,600
            56260,2024,moins_15k,150
            56260,2024,plus_100k,30
            56206,2024,foyers,400
            56206,2024,rfr,10000
            56206,2024,foyers_tranches,400
            56206,2024,moins_15k,50
            56206,2024,plus_100k,10
            56207,2024,foyers,300
            56207,2024,rfr,9000
            99999,2024,foyers,70
            CSV);

        $importer = self::getContainer()->get(TaxIncomeImporter::class);
        \assert($importer instanceof TaxIncomeImporter);

        $importer->import($csvPath);

        // (15 000.5 + 10 000 + 9 000) k€ over 1 300 households: the sums are rounded once, to 34 001 k€.
        self::assertSame(26154.62, $this->decimal('56501', 'revenu_fiscal_moyen'));
        self::assertSame(20.0, $this->decimal('56501', 'part_foyers_moins_15000'));
        self::assertSame(4.0, $this->decimal('56501', 'part_foyers_plus_100000'));
        // A bracket missing from the CSV has no share.
        self::assertNull($this->decimal('56501', 'part_foyers_30000_50000'));
        self::assertNull($this->integer('56501', 'count_foyers'));

        // Few low-income households is better: only that share is ranked from the lowest.
        self::assertTrue($this->rankFromLowest('part_foyers_moins_15000'));
        self::assertFalse($this->rankFromLowest('part_foyers_plus_100000'));

        // Idempotent: a second run updates in place.
        $importer->import($csvPath);
        self::assertSame(26154.62, $this->decimal('56501', 'revenu_fiscal_moyen'));

        unlink($csvPath);
    }

    private function rankFromLowest(string $criterionCode): bool
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);

        return (bool) $entityManager->getConnection()->fetchOne('SELECT rank_from_lowest FROM criterion WHERE code = :code', ['code' => $criterionCode]);
    }

    private function writeCsv(string $content): string
    {
        $csvPath = tempnam(sys_get_temp_dir(), 'tax-income-importer-test-');
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
