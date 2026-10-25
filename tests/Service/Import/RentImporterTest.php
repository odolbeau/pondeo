<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Factory\CommuneFactory;
use App\Factory\UniteUrbaineFactory;
use App\Service\Import\RentImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class RentImporterTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function testImportAveragesCommuneRentsWeightedByPrivateRentalsOfTheLatestCensus(): void
    {
        $unit = UniteUrbaineFactory::createOne(['inseeCode' => '38701']);
        CommuneFactory::createOne(['inseeCode' => '38185', 'uniteUrbaine' => $unit]);
        CommuneFactory::createOne(['inseeCode' => '38421', 'uniteUrbaine' => $unit]);
        CommuneFactory::createOne(['inseeCode' => '38151', 'uniteUrbaine' => $unit]);

        // 38151 has no private rentals: it weighs nothing. 99999 is outside every unité urbaine.
        $rentsPath = $this->csv(<<<'CSV'
            code_insee,year,type,niveau,nb_annonces,loyer_m2
            38185,2025,appartement,commune,7000,14
            38421,2025,appartement,maille,0,10
            38151,2025,appartement,maille,0,30
            38185,2025,maison,maille,40,11.5
            99999,2025,appartement,commune,500,20
            CSV);

        // Only the latest census year weighs, and only the unfurnished private rentals.
        $housingPath = $this->csv(<<<'CSV'
            code_insee,year,status,count
            38185,2017,locataires_prives,100
            38421,2017,locataires_prives,100
            38185,2023,locataires_prives,3000
            38421,2023,locataires_prives,1000
            38185,2023,hlm,90000
            CSV);

        $importer = self::getContainer()->get(RentImporter::class);
        \assert($importer instanceof RentImporter);

        // (3000 × 14 + 1000 × 10) / 4000 = 13
        self::assertSame(2, $importer->import($rentsPath, $housingPath));
        self::assertSame([2025 => '13.00000'], $this->rents('38701', 'loyer_m2_appartement'));
        self::assertSame([2025 => '11.50000'], $this->rents('38701', 'loyer_m2_maison'));
        self::assertSame([], $this->rents('38701', 'loyer_m2_appartement_t1_t2'));

        // Idempotent: a second run updates in place.
        $importer->import($rentsPath, $housingPath);
        self::assertSame([2025 => '13.00000'], $this->rents('38701', 'loyer_m2_appartement'));

        unlink($rentsPath);
        unlink($housingPath);
    }

    public function testImportFailsWithoutRentalsInTheHousingFile(): void
    {
        $rentsPath = $this->csv("code_insee,year,type,niveau,nb_annonces,loyer_m2\n38185,2025,appartement,commune,7000,14");
        $housingPath = $this->csv("code_insee,year,status,count\n38185,2023,hlm,9000");

        $importer = self::getContainer()->get(RentImporter::class);
        \assert($importer instanceof RentImporter);

        try {
            $this->expectException(\RuntimeException::class);
            $importer->import($rentsPath, $housingPath);
        } finally {
            unlink($rentsPath);
            unlink($housingPath);
        }
    }

    private function csv(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'rent-importer-test-');
        \assert(false !== $path);
        file_put_contents($path, $content."\n");

        return $path;
    }

    /**
     * @return array<int, string>
     */
    private function rents(string $uniteUrbaineCode, string $criterionCode): array
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);

        $rows = $entityManager->getConnection()->fetchAllKeyValue(
            <<<'SQL'
                SELECT cv.period, cv.value_decimal
                FROM criterion_value cv
                INNER JOIN unite_urbaine uu ON uu.id = cv.unite_urbaine_id
                INNER JOIN criterion ON criterion.id = cv.criterion_id
                WHERE uu.insee_code = :code AND criterion.code = :criterionCode
                ORDER BY cv.period
                SQL,
            ['code' => $uniteUrbaineCode, 'criterionCode' => $criterionCode],
        );

        $rents = [];
        foreach ($rows as $period => $rent) {
            $rents[(int) $period] = \is_scalar($rent) ? (string) $rent : '';
        }

        return $rents;
    }
}
