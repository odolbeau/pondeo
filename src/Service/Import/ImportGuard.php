<?php

declare(strict_types=1);

namespace App\Service\Import;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Finder\Finder;

final readonly class ImportGuard
{
    /** Every criterion maps communes to their unité urbaine, so they all depend on this file. */
    private const string ZONES_FILE = 'communes-unites-urbaines.csv';

    public function __construct(
        private Connection $connection,
        #[Autowire(param: 'kernel.project_dir')]
        private string $projectDir,
    ) {
    }

    public function isUpToDate(string $name, ImportSource $source): bool
    {
        $storedHash = $this->connection->fetchOne('SELECT source_hash FROM import_state WHERE name = :name', ['name' => $name]);

        return $storedHash === $this->hash($source);
    }

    public function markDone(string $name, ImportSource $source): void
    {
        $this->connection->executeStatement(
            'INSERT INTO import_state (name, source_hash, imported_at) VALUES (:name, :hash, NOW()) ON CONFLICT (name) DO UPDATE SET source_hash = EXCLUDED.source_hash, imported_at = EXCLUDED.imported_at',
            ['name' => $name, 'hash' => $this->hash($source)],
        );
    }

    private function hash(ImportSource $source): string
    {
        $files = array_unique([...$source->files, self::ZONES_FILE]);
        sort($files);

        $context = hash_init('sha256');
        foreach ($files as $file) {
            hash_update($context, $file.':'.$this->hashFile($this->projectDir.'/resources/geo/'.$file));
        }

        // A change to the import logic must re-run the imports too.
        $codeFiles = Finder::create()->files()->name('*.php')->in([$this->projectDir.'/src/Service/Import', $this->projectDir.'/src/Command'])->sort(static fn (\SplFileInfo $a, \SplFileInfo $b): int => $a->getPathname() <=> $b->getPathname());
        // The facility families and shop types, which define the criteria, live with the enums.
        $definitionFiles = Finder::create()->files()->name(['EquipmentFamily.php', 'ShopType.php'])->in($this->projectDir.'/src/Enum')->sort(static fn (\SplFileInfo $a, \SplFileInfo $b): int => $a->getPathname() <=> $b->getPathname());
        foreach ([...$codeFiles, ...$definitionFiles] as $codeFile) {
            hash_update($context, $this->hashFile($codeFile->getPathname()));
        }

        return hash_final($context);
    }

    private function hashFile(string $path): string
    {
        $hash = hash_file('sha256', $path);
        if (false === $hash) {
            throw new \RuntimeException(\sprintf('Cannot read %s', $path));
        }

        return $hash;
    }
}
