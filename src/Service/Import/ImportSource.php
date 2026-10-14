<?php

declare(strict_types=1);

namespace App\Service\Import;

/**
 * Marks an import command as skippable: it is not re-run while these files
 * (relative to resources/geo), the zones file it depends on and its command
 * class are unchanged.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class ImportSource
{
    /** @var list<string> */
    public array $files;

    public function __construct(string ...$files)
    {
        $this->files = array_values($files);
    }
}
