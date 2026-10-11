<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Factory\CommuneFactory;
use App\Factory\UniteUrbaineFactory;
use App\Service\Import\SocioProfessionalImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class SocioProfessionalImporterTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function testImportDerivesTheShareOfEachCategory(): void
    {
        $unit = UniteUrbaineFactory::createOne(['inseeCode' => '56501']);
        CommuneFactory::createOne(['inseeCode' => '56260', 'uniteUrbaine' => $unit]);
        CommuneFactory::createOne(['inseeCode' => '56206', 'uniteUrbaine' => $unit]);

        // 99999 is a commune outside every unité urbaine: skipped.
        $csvPath = $this->writeCsv(<<<'CSV'
            code_insee,year,status,count
            56260,2023,population,1000.5
            56260,2023,cadres,300.4
            56260,2023,retraites,200
            56206,2023,population,1000
            56206,2023,cadres,100
            56206,2023,retraites,400
            99999,2023,population,70
            CSV);

        $importer = self::getContainer()->get(SocioProfessionalImporter::class);
        \assert($importer instanceof SocioProfessionalImporter);

        $importer->import($csvPath);

        // 400 / 2001 and 600 / 2001, from the counts rounded once after summing.
        self::assertSame(19.99, $this->decimal('56501', 'part_cadres'));
        self::assertSame(29.99, $this->decimal('56501', 'part_retraites'));
        // A category missing from the CSV has no share.
        self::assertNull($this->decimal('56501', 'part_ouvriers'));
        self::assertNull($this->integer('56501', 'count_population'));

        // Idempotent: a second run updates in place.
        $importer->import($csvPath);
        self::assertSame(19.99, $this->decimal('56501', 'part_cadres'));

        unlink($csvPath);
    }

    private function writeCsv(string $content): string
    {
        $csvPath = tempnam(sys_get_temp_dir(), 'pcs-importer-test-');
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
