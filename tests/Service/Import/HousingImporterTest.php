<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Factory\CommuneFactory;
use App\Factory\UniteUrbaineFactory;
use App\Service\Import\HousingImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class HousingImporterTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function testImportDerivesTheSharesFromTheSummedCounts(): void
    {
        $unit = UniteUrbaineFactory::createOne(['inseeCode' => '56501']);
        CommuneFactory::createOne(['inseeCode' => '56260', 'uniteUrbaine' => $unit]);
        CommuneFactory::createOne(['inseeCode' => '56206', 'uniteUrbaine' => $unit]);

        // 99999 is a commune outside every unité urbaine: skipped.
        $csvPath = $this->writeCsv(<<<'CSV'
            code_insee,year,status,count
            56260,2023,logements,1100
            56260,2023,vacants,100
            56260,2023,residences_principales,900
            56260,2023,proprietaires,300
            56260,2023,hlm,150
            56206,2023,logements,900
            56206,2023,vacants,50
            56206,2023,residences_principales,100
            56206,2023,proprietaires,100
            56206,2023,hlm,0
            99999,2023,logements,70
            CSV);

        $importer = self::getContainer()->get(HousingImporter::class);
        \assert($importer instanceof HousingImporter);

        $importer->import($csvPath);

        // 400 / 1000 owners, 150 / 1000 social housing, 150 / 2000 vacant.
        self::assertSame(40.0, $this->decimal('56501', 'part_proprietaires'));
        self::assertSame(15.0, $this->decimal('56501', 'part_logement_social'));
        self::assertSame(7.5, $this->decimal('56501', 'part_logements_vacants'));
        self::assertNull($this->integer('56501', 'count_logements'));

        // Idempotent: a second run updates in place.
        $importer->import($csvPath);
        self::assertSame(40.0, $this->decimal('56501', 'part_proprietaires'));

        unlink($csvPath);
    }

    private function writeCsv(string $content): string
    {
        $csvPath = tempnam(sys_get_temp_dir(), 'housing-importer-test-');
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
