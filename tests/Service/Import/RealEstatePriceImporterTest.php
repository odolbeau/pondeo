<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Factory\CommuneFactory;
use App\Factory\UniteUrbaineFactory;
use App\Service\Import\RealEstatePriceImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class RealEstatePriceImporterTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function testImportAveragesCommunePricesWeightedBySalesPerUniteUrbaine(): void
    {
        $unit = UniteUrbaineFactory::createOne(['inseeCode' => '56501']);
        CommuneFactory::createOne(['inseeCode' => '56260', 'uniteUrbaine' => $unit]);
        CommuneFactory::createOne(['inseeCode' => '56206', 'uniteUrbaine' => $unit]);

        // 99999 is a commune outside every unité urbaine: skipped.
        $csvPath = tempnam(sys_get_temp_dir(), 'real-estate-importer-test-');
        \assert(false !== $csvPath);
        file_put_contents($csvPath, <<<'CSV'
            code_insee,year,type,nb_ventes,prix_m2
            56260,2024,apt_maison,100,3000
            56206,2024,apt_maison,300,4000
            56260,2025,apt_maison,10,5000
            56260,2025,maison,10,2500
            99999,2025,apt_maison,50,9000
            CSV."\n");

        $importer = self::getContainer()->get(RealEstatePriceImporter::class);
        \assert($importer instanceof RealEstatePriceImporter);

        // (100 × 3000 + 300 × 4000) / 400 = 3750
        self::assertSame(3, $importer->import($csvPath));
        self::assertSame([2024 => 3750, 2025 => 5000], $this->prices('56501', 'prix_m2'));
        self::assertSame([2025 => 2500], $this->prices('56501', 'prix_m2_maison'));
        self::assertSame([], $this->prices('56501', 'prix_m2_appartement'));

        // Idempotent: a second run updates in place.
        $importer->import($csvPath);
        self::assertSame([2024 => 3750, 2025 => 5000], $this->prices('56501', 'prix_m2'));

        unlink($csvPath);
    }

    /**
     * @return array<int, int>
     */
    private function prices(string $uniteUrbaineCode, string $criterionCode): array
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);

        $rows = $entityManager->getConnection()->fetchAllKeyValue(
            <<<'SQL'
                SELECT cv.period, cv.value_integer
                FROM criterion_value cv
                INNER JOIN unite_urbaine uu ON uu.id = cv.unite_urbaine_id
                INNER JOIN criterion ON criterion.id = cv.criterion_id
                WHERE uu.insee_code = :code AND criterion.code = :criterionCode
                ORDER BY cv.period
                SQL,
            ['code' => $uniteUrbaineCode, 'criterionCode' => $criterionCode],
        );

        $prices = [];
        foreach ($rows as $period => $price) {
            $prices[(int) $period] = (int) (is_numeric($price) ? $price : 0);
        }

        return $prices;
    }
}
