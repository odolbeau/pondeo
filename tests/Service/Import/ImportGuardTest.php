<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Command\ImportCriteriaIncomeCommand;
use App\Command\ImportCriteriaPcsCommand;
use App\Service\Import\ImportGuard;
use App\Service\Import\ImportSource;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Zenstruck\Foundry\Test\ResetDatabase;

final class ImportGuardTest extends KernelTestCase
{
    use ResetDatabase;

    private string $projectDir;

    protected function tearDown(): void
    {
        if (isset($this->projectDir)) {
            new Filesystem()->remove($this->projectDir);
        }

        parent::tearDown();
    }

    public function testAnImportIsUpToDateOnlyOnceDoneAndWhileItsSourcesAreUnchanged(): void
    {
        $guard = self::getContainer()->get(ImportGuard::class);
        \assert($guard instanceof ImportGuard);

        $income = new ImportSource('revenus-filosofi.csv');
        $pcs = new ImportSource('pcs-recensement.csv');

        self::assertFalse($guard->isUpToDate('app:import:test', $income, ImportCriteriaIncomeCommand::class));

        $guard->markDone('app:import:test', $income, ImportCriteriaIncomeCommand::class);
        self::assertTrue($guard->isUpToDate('app:import:test', $income, ImportCriteriaIncomeCommand::class));

        $guard->markDone('app:import:test', $income, ImportCriteriaIncomeCommand::class); // Re-marking replaces the stored state.
        self::assertTrue($guard->isUpToDate('app:import:test', $income, ImportCriteriaIncomeCommand::class));

        self::assertFalse($guard->isUpToDate('app:import:test', $pcs, ImportCriteriaPcsCommand::class), 'Other sources hash differently.');
    }

    public function testAnImportRerunsOnlyWhenItsOwnCommandChanges(): void
    {
        $guard = $this->guardOverAFakeProject([
            'src/Command/Foo/ImportFooCommand.php' => '<?php // v1',
            'src/Command/Foo/ImportBarCommand.php' => '<?php // v1',
            'src/Service/Import/FooImporter.php' => '<?php // v1',
        ]);
        $source = new ImportSource('foo.csv');

        $guard->markDone('app:import:foo', $source, 'App\Command\Foo\ImportFooCommand');
        $guard->markDone('app:import:bar', $source, 'App\Command\Foo\ImportBarCommand');

        $this->write('src/Command/Foo/ImportNewCommand.php', '<?php // added');
        $this->write('src/Service/Import/FooImporter.php', '<?php // v2');
        self::assertTrue($guard->isUpToDate('app:import:foo', $source, 'App\Command\Foo\ImportFooCommand'), 'Adding a command or changing an importer leaves it alone.');
        self::assertTrue($guard->isUpToDate('app:import:bar', $source, 'App\Command\Foo\ImportBarCommand'));

        $this->write('src/Command/Foo/ImportFooCommand.php', '<?php // v2');
        self::assertFalse($guard->isUpToDate('app:import:foo', $source, 'App\Command\Foo\ImportFooCommand'), 'Its command changed.');
        self::assertTrue($guard->isUpToDate('app:import:bar', $source, 'App\Command\Foo\ImportBarCommand'), 'The other import is unaffected.');
    }

    public function testAnImportRerunsWhenItsSourceOrTheZonesChange(): void
    {
        $guard = $this->guardOverAFakeProject(['src/Command/Foo/ImportFooCommand.php' => '<?php']);
        $source = new ImportSource('foo.csv');

        $guard->markDone('app:import:foo', $source, 'App\Command\Foo\ImportFooCommand');

        $this->write('resources/geo/foo.csv', 'foo v2');
        self::assertFalse($guard->isUpToDate('app:import:foo', $source, 'App\Command\Foo\ImportFooCommand'));

        $guard->markDone('app:import:foo', $source, 'App\Command\Foo\ImportFooCommand');
        $this->write('resources/geo/communes-unites-urbaines.csv', 'zones v2');
        self::assertFalse($guard->isUpToDate('app:import:foo', $source, 'App\Command\Foo\ImportFooCommand'));
    }

    /**
     * @param array<string, string> $files
     */
    private function guardOverAFakeProject(array $files): ImportGuard
    {
        $this->projectDir = sys_get_temp_dir().'/import-guard-test-'.bin2hex(random_bytes(4));
        foreach (['src/Service/Import', 'src/Command', 'src/Enum', 'resources/geo'] as $directory) {
            mkdir($this->projectDir.'/'.$directory, recursive: true);
        }

        $this->write('resources/geo/communes-unites-urbaines.csv', 'zones');
        $this->write('resources/geo/foo.csv', 'foo');
        foreach ($files as $path => $content) {
            $this->write($path, $content);
        }

        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);

        return new ImportGuard($connection, $this->projectDir);
    }

    private function write(string $path, string $content): void
    {
        $file = $this->projectDir.'/'.$path;
        new Filesystem()->mkdir(\dirname($file));
        file_put_contents($file, $content);
    }
}
