<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Factory\CommuneFactory;
use App\Factory\UniteUrbaineFactory;
use App\Service\Import\CsvCriterionImporter;
use App\Service\Import\RiskCriteria;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class RiskCriteriaImportTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function testImportCombinesTheCommunesPerUniteUrbaineByCriterion(): void
    {
        $unit = UniteUrbaineFactory::createOne(['inseeCode' => '06701']);
        CommuneFactory::createOne(['inseeCode' => '06088', 'uniteUrbaine' => $unit]);
        CommuneFactory::createOne(['inseeCode' => '06029', 'uniteUrbaine' => $unit]);

        // Nice (300 000 inhabitants) and a small neighbour (100 000): the flood share is the
        // population-weighted 0/100 flag, the seismic zone the highest of the two. The radon
        // row of the neighbour is missing: it does not pull the mean.
        $csv = tempnam(sys_get_temp_dir(), 'risks-test-');
        \assert(false !== $csv);
        file_put_contents($csv, <<<'CSV'
            code_insee,year,measure,value,population
            06088,2026,catnat,6,300000
            06029,2026,catnat,2,100000
            06088,2026,inondation,100,300000
            06029,2026,inondation,0,100000
            06088,2026,zone_sismique,4,300000
            06029,2026,zone_sismique,3,100000
            06088,2026,radon_fort,0,300000
            06088,2026,argiles,1,300000
            06029,2026,argiles,3,100000
            99999,2026,inondation,100,5000000
            CSV);

        $importer = self::getContainer()->get(CsvCriterionImporter::class);
        \assert($importer instanceof CsvCriterionImporter);

        $imported = 0;
        foreach (RiskCriteria::specs() as $spec) {
            $imported += $importer->import($spec, $csv);
        }

        // feu_foret has no row at all: 5 criteria carry a value for the unit.
        self::assertSame(5, $imported);
        self::assertEqualsWithDelta(5.0, $this->value('arretes_catnat'), 0.0001);
        self::assertEqualsWithDelta(75.0, $this->value('part_zone_inondable'), 0.0001);
        self::assertEqualsWithDelta(1.5, $this->value('argiles'), 0.0001);
        self::assertEqualsWithDelta(0.0, $this->value('part_radon_fort'), 0.0001);
        self::assertEquals(4, $this->value('zone_sismique'));
        self::assertNull($this->value('part_feux_foret'));

        unlink($csv);
    }

    private function value(string $criterionCode): float|int|null
    {
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);

        /** @var array{value_integer: int|null, value_decimal: string|null, group_label: string}|false $row */
        $row = $connection->fetchAssociative(
            'SELECT cv.value_integer, cv.value_decimal, c.group_label FROM criterion_value cv INNER JOIN criterion c ON c.id = cv.criterion_id WHERE c.code = :code',
            ['code' => $criterionCode],
        );
        if (false === $row) {
            return null;
        }

        self::assertSame('Risques', $row['group_label']);

        return $row['value_integer'] ?? (float) $row['value_decimal'];
    }
}
