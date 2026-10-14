<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Enum\CriterionValueType;
use App\Factory\CommuneFactory;
use App\Factory\CriterionFactory;
use App\Factory\UniteUrbaineFactory;
use App\Service\Import\AssociationsImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class AssociationsImporterTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function testImportSumsCountsPerUniteUrbaineAndDerivesDensities(): void
    {
        $unit = UniteUrbaineFactory::createOne(['inseeCode' => '56501']);
        CommuneFactory::createOne(['inseeCode' => '56260', 'uniteUrbaine' => $unit]);
        CommuneFactory::createOne(['inseeCode' => '56206', 'uniteUrbaine' => $unit]);
        $emptyUnit = UniteUrbaineFactory::createOne(['inseeCode' => '44701']);
        CommuneFactory::createOne(['inseeCode' => '44109', 'uniteUrbaine' => $emptyUnit]);

        $this->createPopulation('56501', 2023, 50_000);
        $this->createPopulation('44701', 2023, 0);

        // 99999 is a commune outside every unité urbaine: skipped.
        $csvPath = $this->writeCsv(<<<'CSV'
            code_insee,year,category,count
            56260,2026,all,300
            56260,2026,active,200
            56260,2026,sport,50
            56206,2026,all,100
            56206,2026,active,50
            56206,2026,sport,30
            56206,2026,sante,10
            44109,2026,all,3
            99999,2026,all,7
            CSV);

        $importer = self::getContainer()->get(AssociationsImporter::class);
        \assert($importer instanceof AssociationsImporter);

        $importer->import($csvPath);

        self::assertSame(400, $this->integer('56501', 'associations_total'));
        self::assertSame(250, $this->integer('56501', 'associations_actives'));
        self::assertSame(80, $this->integer('56501', 'associations_sport'));
        self::assertSame(10, $this->integer('56501', 'associations_sante'));
        self::assertSame(8.0, $this->decimal('56501', 'associations_total_pour_1000_hab'));
        self::assertSame(5.0, $this->decimal('56501', 'associations_actives_pour_1000_hab'));
        self::assertSame(1.6, $this->decimal('56501', 'associations_sport_pour_1000_hab'));
        // A unité urbaine without population has a count but no density.
        self::assertSame(3, $this->integer('44701', 'associations_total'));
        self::assertNull($this->decimal('44701', 'associations_total_pour_1000_hab'));
        // No association of that family: no value rather than a zero.
        self::assertNull($this->integer('44701', 'associations_sport'));

        // Raw counts are not ranked on the detail page, densities are.
        self::assertSame([false, true], $this->comparableFlags('associations_total', 'associations_total_pour_1000_hab'));

        // Idempotent: a second run updates in place.
        $importer->import($csvPath);
        self::assertSame(400, $this->integer('56501', 'associations_total'));
        self::assertSame(8.0, $this->decimal('56501', 'associations_total_pour_1000_hab'));

        unlink($csvPath);
    }

    /**
     * @return list<bool>
     */
    private function comparableFlags(string ...$codes): array
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);

        return array_values(array_map(
            static fn (string $code): bool => (bool) $entityManager->getConnection()->fetchOne('SELECT comparable FROM criterion WHERE code = :code', ['code' => $code]),
            $codes,
        ));
    }

    private function createPopulation(string $uniteUrbaineCode, int $period, int $population): void
    {
        $criterion = CriterionFactory::findOrCreate(['code' => 'population', 'valueType' => CriterionValueType::Integer]);
        $connection = self::getContainer()->get(EntityManagerInterface::class);
        \assert($connection instanceof EntityManagerInterface);

        $connection->getConnection()->executeStatement(
            <<<'SQL'
                INSERT INTO criterion_value (id, criterion_id, unite_urbaine_id, period, value_integer, created_at, updated_at)
                SELECT gen_random_uuid(), :criterionId, uu.id, :period, :population, NOW(), NOW()
                FROM unite_urbaine uu WHERE uu.insee_code = :code
                SQL,
            ['criterionId' => $criterion->getId()->toRfc4122(), 'period' => $period, 'population' => $population, 'code' => $uniteUrbaineCode],
        );
    }

    private function writeCsv(string $content): string
    {
        $csvPath = tempnam(sys_get_temp_dir(), 'associations-importer-test-');
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
