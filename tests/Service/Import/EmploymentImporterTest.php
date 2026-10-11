<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Factory\CommuneFactory;
use App\Factory\UniteUrbaineFactory;
use App\Service\Import\EmploymentImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class EmploymentImporterTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function testImportSumsCountsPerUniteUrbaineAndDerivesRates(): void
    {
        $unit = UniteUrbaineFactory::createOne(['inseeCode' => '56501']);
        CommuneFactory::createOne(['inseeCode' => '56260', 'uniteUrbaine' => $unit]);
        CommuneFactory::createOne(['inseeCode' => '56206', 'uniteUrbaine' => $unit]);
        $emptyUnit = UniteUrbaineFactory::createOne(['inseeCode' => '44701']);
        CommuneFactory::createOne(['inseeCode' => '44109', 'uniteUrbaine' => $emptyUnit]);

        // 99999 is a commune outside every unité urbaine: skipped. 44109 has no 15-64 population: no rates.
        $csvPath = $this->writeCsv(<<<'CSV'
            code_insee,year,status,count
            56260,2023,actifs,300.5
            56260,2023,chomeurs,30
            56260,2023,population,400
            56206,2023,actifs,200
            56206,2023,chomeurs,20
            56206,2023,population,350
            44109,2023,actifs,10
            99999,2023,actifs,70
            CSV);

        $importer = self::getContainer()->get(EmploymentImporter::class);
        \assert($importer instanceof EmploymentImporter);

        $importer->import($csvPath);

        // Rates use the stored (rounded) counts: 50 / 501 and 501 / 750.
        self::assertSame(9.98, $this->decimal('56501', 'taux_chomage'));
        self::assertSame(66.8, $this->decimal('56501', 'taux_activite'));
        self::assertNull($this->decimal('44701', 'taux_chomage'));

        // Idempotent: a second run updates in place.
        $importer->import($csvPath);
        self::assertNull($this->integer('56501', 'actifs_15_64'));
        self::assertSame(9.98, $this->decimal('56501', 'taux_chomage'));

        unlink($csvPath);
    }

    private function writeCsv(string $content): string
    {
        $csvPath = tempnam(sys_get_temp_dir(), 'employment-importer-test-');
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
