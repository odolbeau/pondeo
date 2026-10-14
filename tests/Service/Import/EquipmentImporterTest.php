<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Enum\CriterionValueType;
use App\Enum\EquipmentFamily;
use App\Factory\CriterionFactory;
use App\Factory\UniteUrbaineFactory;
use App\Service\Import\CsvReader;
use App\Service\Import\EquipmentImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class EquipmentImporterTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function testImportSumsTheTypesOfEachFamilyPerUniteUrbaineAndDerivesDensities(): void
    {
        UniteUrbaineFactory::createOne(['inseeCode' => '56501']);
        UniteUrbaineFactory::createOne(['inseeCode' => '44701']);

        $this->createPopulation('56501', 2023, 50_000);
        $this->createPopulation('44701', 2023, 0);

        // 99999 is an unité urbaine out of scope, B999 a type the file format does not know: both skipped.
        $csvPath = $this->writeCsv(<<<'CSV'
            code_unite_urbaine,year,type,count
            56501,2025,B104,5
            56501,2025,B207,30
            56501,2025,B204,20
            56501,2025,B302,30
            56501,2025,B316,15
            56501,2025,B999,8
            56501,2015,B207,40
            56501,2025,D265,60
            56501,2025,D307,15
            56501,2025,D277,10
            56501,2025,D999,4
            44701,2025,B201,3
            99999,2025,B207,7
            CSV);

        $importer = self::getContainer()->get(EquipmentImporter::class);
        \assert($importer instanceof EquipmentImporter);

        $importer->import($csvPath);

        self::assertSame(100, $this->integer('56501', 'commerces_total'));
        self::assertSame(5, $this->integer('56501', 'commerces_grandes_surfaces'));
        self::assertSame(50, $this->integer('56501', 'commerces_alimentaires'));
        self::assertSame(45, $this->integer('56501', 'commerces_non_alimentaires'));
        self::assertSame(2.0, $this->decimal('56501', 'commerces_total_pour_1000_hab'));
        self::assertSame(1.0, $this->decimal('56501', 'commerces_alimentaires_pour_1000_hab'));
        // The other domains are criteria of their own, outside the shop totals; a type no family counts is ignored.
        self::assertSame(60, $this->integer('56501', 'medecins_generalistes'));
        self::assertSame(15, $this->integer('56501', 'pharmacies'));
        self::assertSame(1.2, $this->decimal('56501', 'medecins_generalistes_pour_1000_hab'));
        self::assertSame(0, $this->integer('56501', 'medecins_specialistes'));
        self::assertSame(['Santé', 'Commerces'], $this->groupLabels('medecins_generalistes', 'commerces_total'));

        // Each year of the file is its own period.
        self::assertSame(40, $this->integer('56501', 'commerces_alimentaires', 2015));
        self::assertSame(0, $this->integer('56501', 'commerces_grandes_surfaces', 2015));
        // A unité urbaine without population has a count but no density.
        self::assertSame(3, $this->integer('44701', 'commerces_total'));
        self::assertNull($this->decimal('44701', 'commerces_total_pour_1000_hab'));

        // Raw counts are not ranked on the detail page, densities are.
        self::assertSame([false, true], $this->comparableFlags('commerces_total', 'commerces_total_pour_1000_hab'));

        // The detail by type is kept as is, one count per year (zero when the file has no row for it).
        self::assertSame(
            ['years' => [2015, 2025], 'counts' => ['B104' => [0, 5], 'B204' => [0, 20], 'B207' => [40, 30], 'B302' => [0, 30], 'B316' => [0, 15]]],
            $this->shopTypes('56501'),
        );
        self::assertNull($this->shopTypes('99999'));

        // Idempotent: a second run updates in place.
        $importer->import($csvPath);
        self::assertSame(100, $this->integer('56501', 'commerces_total'));
        self::assertSame(2.0, $this->decimal('56501', 'commerces_total_pour_1000_hab'));

        unlink($csvPath);
    }

    public function testEveryFamilyTypeIsInTheCatalogueOfTheFile(): void
    {
        $catalogue = [];
        foreach (new CsvReader()->read(\dirname(__DIR__, 3).'/resources/geo/equipements-bpe-types.csv') as $row) {
            $catalogue[] = $row['type'];
        }

        foreach (EquipmentFamily::cases() as $family) {
            self::assertSame([], array_values(array_diff($family->types(), $catalogue)), $family->value);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function shopTypes(string $uniteUrbaineCode): ?array
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);

        $json = $entityManager->getConnection()->fetchOne('SELECT shop_types FROM unite_urbaine WHERE insee_code = :code', ['code' => $uniteUrbaineCode]);
        if (!\is_string($json)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * @return list<string>
     */
    private function groupLabels(string ...$codes): array
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);

        return array_values(array_map(
            static function (string $code) use ($entityManager): string {
                $group = $entityManager->getConnection()->fetchOne('SELECT group_label FROM criterion WHERE code = :code', ['code' => $code]);

                return \is_string($group) ? $group : '';
            },
            $codes,
        ));
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
        $csvPath = tempnam(sys_get_temp_dir(), 'commerces-importer-test-');
        \assert(false !== $csvPath);
        file_put_contents($csvPath, $content."\n");

        return $csvPath;
    }

    private function integer(string $uniteUrbaineCode, string $criterionCode, int $period = 2025): ?int
    {
        $value = $this->column('value_integer', $uniteUrbaineCode, $criterionCode, $period);

        return is_numeric($value) ? (int) $value : null;
    }

    private function decimal(string $uniteUrbaineCode, string $criterionCode, int $period = 2025): ?float
    {
        $value = $this->column('value_decimal', $uniteUrbaineCode, $criterionCode, $period);

        return is_numeric($value) ? (float) $value : null;
    }

    // Read through DBAL: see CsvCriterionImporterTest::valuesByPeriod().
    private function column(string $column, string $uniteUrbaineCode, string $criterionCode, int $period): mixed
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
                    WHERE uu.insee_code = :code AND criterion.code = :criterionCode AND cv.period = :period
                    SQL,
                $column,
            ),
            ['code' => $uniteUrbaineCode, 'criterionCode' => $criterionCode, 'period' => $period],
        );

        return false === $value ? null : $value;
    }
}
