<?php

declare(strict_types=1);

namespace App\Service\Import;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Keeps what the Open-Meteo Climate API answered, outside the repository: one
 * gzip file per grid cell, period, list of models and list of variables, with
 * the daily series of every model as received (see
 * `docs/criteria-climate-projections.md`).
 */
final readonly class ClimateProjectionArchive
{
    private string $dir;

    public function __construct(
        #[Autowire(env: 'OPENMETEO_ARCHIVE_DIR')]
        string $dir,
    ) {
        $this->dir = '' !== $dir ? $dir : (getenv('HOME') ?: sys_get_temp_dir()).'/pondeo-data/open-meteo/projections';
    }

    public function getDir(): string
    {
        return $this->dir;
    }

    /**
     * @param array{string, string} $period
     * @param list<string>          $models
     * @param list<string>          $variables
     */
    public function has(string $cell, array $period, array $models, array $variables): bool
    {
        return is_file($this->path($cell, $period, $models, $variables));
    }

    /**
     * @param array{string, string} $period
     * @param list<string>          $models
     * @param list<string>          $variables
     *
     * @return array{daily: array<string, list<float|string|null>>}
     */
    public function read(string $cell, array $period, array $models, array $variables): array
    {
        $path = $this->path($cell, $period, $models, $variables);
        $content = file_get_contents('compress.zlib://'.$path);
        if (false === $content) {
            throw new \RuntimeException("Cannot read {$path}");
        }

        /** @var array{daily: array<string, list<float|string|null>>} $location */
        $location = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);

        return $location;
    }

    /**
     * @param array{string, string}                                   $period
     * @param list<string>                                            $models
     * @param list<string>                                            $variables
     * @param array{daily: array<string, mixed>}|array<string, mixed> $location  the API's answer for the cell
     */
    public function write(string $cell, array $period, array $models, array $variables, array $location): void
    {
        $path = $this->path($cell, $period, $models, $variables);
        if (!is_dir(\dirname($path)) && !mkdir(\dirname($path), 0o775, true) && !is_dir(\dirname($path))) {
            throw new \RuntimeException('Cannot create '.\dirname($path));
        }

        // Written aside then renamed: an interrupted run never leaves a truncated file that counts as done.
        $temporary = $path.'.tmp';
        if (false === file_put_contents($temporary, gzencode(json_encode($location, \JSON_THROW_ON_ERROR), 9))) {
            throw new \RuntimeException("Cannot write {$temporary}");
        }

        rename($temporary, $path);
    }

    // The estimated cost of each request, to account for the quota across runs.
    public function logRequest(string $line): void
    {
        file_put_contents($this->dir.'/requests.log', $line."\n", \FILE_APPEND);
    }

    /**
     * @param array{string, string} $period
     * @param list<string>          $models
     * @param list<string>          $variables
     */
    private function path(string $cell, array $period, array $models, array $variables): string
    {
        return \sprintf('%s/%s/%s_%s__%s__%s.json.gz', $this->dir, str_replace(',', '_', $cell), $period[0], $period[1], implode('+', $models), implode('+', $variables));
    }
}
