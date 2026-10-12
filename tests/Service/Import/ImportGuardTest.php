<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Service\Import\ImportGuard;
use App\Service\Import\ImportSource;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
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
}
