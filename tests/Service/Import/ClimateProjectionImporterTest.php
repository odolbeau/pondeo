<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Factory\UniteUrbaineFactory;
use App\Service\Import\ClimateProjectionImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class ClimateProjectionImporterTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function testImportStoresTheProjectionsOfEachUniteUrbaineUnderTheClimate2050Group(): void
    {
        UniteUrbaineFactory::createOne(['inseeCode' => '13701']);
        UniteUrbaineFactory::createOne(['inseeCode' => '29201']);

        // 99999 is not one of ours.
        $csvPath = tempnam(sys_get_temp_dir(), 'climate-projections-test-');
        \assert(false !== $csvPath);
        file_put_contents($csvPath, <<<'CSV'
            code_unite_urbaine,year,measure,value
            13701,2050,hot_days,18.3
            13701,2050,tropical_nights,24.1
            13701,2050,warming,2.05
            29201,2050,hot_days,0.4
            99999,2050,hot_days,1
            CSV."\n");

        $importer = self::getContainer()->get(ClimateProjectionImporter::class);
        \assert($importer instanceof ClimateProjectionImporter);

        self::assertSame(4, $importer->import($csvPath));
        self::assertSame(18.3, $this->decimal('13701', 'jours_plus_35_2050'));
        self::assertSame(2.05, $this->decimal('13701', 'hausse_temperature_2050'));
        self::assertNull($this->decimal('29201', 'nuits_tropicales_2050'));

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);
        $connection = $entityManager->getConnection();
        self::assertSame('Climat 2050', $connection->fetchOne("SELECT group_label FROM criterion WHERE code = 'jours_plus_35_2050'"));
        self::assertTrue((bool) $connection->fetchOne("SELECT rank_from_lowest FROM criterion WHERE code = 'jours_plus_35_2050'"));
        self::assertEquals(2050, $connection->fetchOne('SELECT DISTINCT period FROM criterion_value'));

        // Idempotent: a second run updates in place.
        self::assertSame(4, $importer->import($csvPath));
        self::assertEquals(4, $connection->fetchOne('SELECT COUNT(*) FROM criterion_value'));

        unlink($csvPath);
    }

    private function decimal(string $uniteUrbaineCode, string $criterionCode): ?float
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);

        $value = $entityManager->getConnection()->fetchOne(
            <<<'SQL'
                SELECT cv.value_decimal
                FROM criterion_value cv
                INNER JOIN unite_urbaine uu ON uu.id = cv.unite_urbaine_id
                INNER JOIN criterion ON criterion.id = cv.criterion_id
                WHERE uu.insee_code = :code AND criterion.code = :criterionCode
                SQL,
            ['code' => $uniteUrbaineCode, 'criterionCode' => $criterionCode],
        );

        return is_numeric($value) ? (float) $value : null;
    }
}
