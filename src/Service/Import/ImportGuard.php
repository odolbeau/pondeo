<?php

declare(strict_types=1);

namespace App\Service\Import;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

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

    public function isUpToDate(string $name, ImportSource $source, string $commandClass): bool
    {
        $storedHash = $this->connection->fetchOne('SELECT source_hash FROM import_state WHERE name = :name', ['name' => $name]);

        return $storedHash === $this->hash($source, $commandClass);
    }

    public function markDone(string $name, ImportSource $source, string $commandClass): void
    {
        $this->connection->executeStatement(
            'INSERT INTO import_state (name, source_hash, imported_at) VALUES (:name, :hash, NOW()) ON CONFLICT (name) DO UPDATE SET source_hash = EXCLUDED.source_hash, imported_at = EXCLUDED.imported_at',
            ['name' => $name, 'hash' => $this->hash($source, $commandClass)],
        );
    }

    private function hash(ImportSource $source, string $commandClass): string
    {
        $files = array_unique([...$source->files, self::ZONES_FILE]);
        sort($files);

        $context = hash_init('sha256');
        foreach ($files as $file) {
            hash_update($context, $file.':'.$this->hashFile($this->projectDir.'/resources/geo/'.$file));
        }

        // A change to the command must re-run it, and only it. The code it
        // delegates to is not tracked: touching a shared class re-runs nothing.
        hash_update($context, $this->hashFile($this->projectDir.'/src/'.str_replace('\\', '/', substr($commandClass, \strlen('App\\'))).'.php'));

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
