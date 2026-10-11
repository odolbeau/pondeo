<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Factory\CommuneFactory;
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

    public function testImportAveragesCommunesWeightedByPopulation(): void
    {
        $unit = UniteUrbaineFactory::createOne(['inseeCode' => '56501']);
        CommuneFactory::createOne(['inseeCode' => '56260', 'uniteUrbaine' => $unit]);
        CommuneFactory::createOne(['inseeCode' => '56206', 'uniteUrbaine' => $unit]);
        $unitWithoutFigure = UniteUrbaineFactory::createOne(['inseeCode' => '44701']);
        CommuneFactory::createOne(['inseeCode' => '44109', 'uniteUrbaine' => $unitWithoutFigure]);

        $populationPath = $this->writeCsv(<<<'CSV'
            code_insee,year,population
            56260,2023,3000
            56206,2023,1000
            44109,2023,500
            CSV);
        // 56206 has no poverty rate (statistical secrecy): the unit's rate rests on 56260 alone.
        // 99999 is a commune outside every unité urbaine: skipped.
        $incomePath = $this->writeCsv(<<<'CSV'
            code_insee,year,measure,value
            56260,2023,median,24000
            56206,2023,median,28000
            56260,2023,poverty,10.5
            99999,2023,median,99000
            CSV);

        $importer = self::getContainer()->get(IncomeImporter::class);
        \assert($importer instanceof IncomeImporter);

        $importer->import($incomePath, $populationPath);

        // (3000 * 24000 + 1000 * 28000) / 4000
        self::assertSame(25000, $this->integer('56501', 'niveau_vie_median'));
        self::assertSame(10.5, $this->decimal('56501', 'taux_pauvrete'));
        self::assertNull($this->integer('44701', 'niveau_vie_median'));

        // Idempotent: a second run updates in place.
        $importer->import($incomePath, $populationPath);
        self::assertSame(25000, $this->integer('56501', 'niveau_vie_median'));

        unlink($incomePath);
        unlink($populationPath);
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
