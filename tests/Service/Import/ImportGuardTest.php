<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Service\Import\ImportGuard;
use App\Service\Import\ImportSource;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Zenstruck\Foundry\Test\ResetDatabase;

final class ImportGuardTest extends KernelTestCase
{
    use ResetDatabase;

    public function testAnImportIsUpToDateOnlyOnceDoneAndWhileItsSourcesAreUnchanged(): void
    {
        $guard = self::getContainer()->get(ImportGuard::class);
        \assert($guard instanceof ImportGuard);

        $income = new ImportSource('revenus-filosofi.csv');
        $pcs = new ImportSource('pcs-recensement.csv');

        self::assertFalse($guard->isUpToDate('app:import:test', $income));

        $guard->markDone('app:import:test', $income);
        self::assertTrue($guard->isUpToDate('app:import:test', $income));

        $guard->markDone('app:import:test', $income); // Re-marking replaces the stored state.
        self::assertTrue($guard->isUpToDate('app:import:test', $income));

        self::assertFalse($guard->isUpToDate('app:import:test', $pcs), 'Other sources hash differently.');
    }

    public function testAnImportRerunsWhenTheDefinitionOfAFacilityFamilyChanges(): void
    {
        $projectDir = sys_get_temp_dir().'/import-guard-test-'.bin2hex(random_bytes(4));
        foreach (['src/Service/Import', 'src/Command', 'src/Enum', 'resources/geo'] as $directory) {
            mkdir($projectDir.'/'.$directory, recursive: true);
        }

        file_put_contents($projectDir.'/resources/geo/communes-unites-urbaines.csv', 'zones');
        file_put_contents($projectDir.'/resources/geo/equipements-bpe.csv', 'facilities');
        file_put_contents($projectDir.'/src/Enum/EquipmentFamily.php', '<?php // v1');
        file_put_contents($projectDir.'/src/Enum/DetailSection.php', '<?php // v1');

        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        $guard = new ImportGuard($connection, $projectDir);
        $source = new ImportSource('equipements-bpe.csv');

        $guard->markDone('app:import:test', $source);
        self::assertTrue($guard->isUpToDate('app:import:test', $source));

        file_put_contents($projectDir.'/src/Enum/DetailSection.php', '<?php // v2');
        self::assertTrue($guard->isUpToDate('app:import:test', $source), 'An unrelated enum does not rerun the imports.');

        file_put_contents($projectDir.'/src/Enum/EquipmentFamily.php', '<?php // v2');
        self::assertFalse($guard->isUpToDate('app:import:test', $source));

        new Filesystem()->remove($projectDir);
    }
}
