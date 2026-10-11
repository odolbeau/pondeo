<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Entity\UniteUrbaine;
use App\Factory\UniteUrbaineFactory;
use App\Service\Import\AgePyramidImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class AgePyramidImporterTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function testImportStoresTheBracketsOfEachUniteUrbaine(): void
    {
        UniteUrbaineFactory::createOne(['inseeCode' => '56501']);
        UniteUrbaineFactory::createOne(['inseeCode' => '44701']);

        // Brackets arrive in any order; 99999 is not one of ours, and a missing bracket counts for 0.
        $csvPath = tempnam(sys_get_temp_dir(), 'age-pyramid-importer-test-');
        \assert(false !== $csvPath);
        file_put_contents($csvPath, <<<'CSV'
            code_insee,year,sex,age_from,count
            56501,2023,F,5,20
            56501,2023,F,0,30
            56501,2023,M,0,40
            56501,2023,M,95,3
            99999,2023,F,0,7
            CSV."\n");

        $importer = self::getContainer()->get(AgePyramidImporter::class);
        \assert($importer instanceof AgePyramidImporter);

        self::assertSame(1, $importer->import($csvPath));

        $pyramid = $this->pyramid('56501');
        self::assertNotNull($pyramid);
        self::assertSame(2023, $pyramid['year']);
        self::assertCount(20, $pyramid['women']);
        self::assertSame([30, 20], \array_slice($pyramid['women'], 0, 2));
        self::assertSame(40, $pyramid['men'][0]);
        self::assertSame(3, $pyramid['men'][19]);
        self::assertNull($this->pyramid('44701'));

        // Idempotent: a second run overwrites.
        self::assertSame(1, $importer->import($csvPath));
        self::assertSame(30, $this->pyramid('56501')['women'][0] ?? null);

        unlink($csvPath);
    }

    /**
     * @return array{year: int, women: list<int>, men: list<int>}|null
     */
    private function pyramid(string $code): ?array
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);
        $entityManager->clear();

        return $entityManager->getRepository(UniteUrbaine::class)->findOneBy(['inseeCode' => $code])?->getAgePyramid();
    }
}
