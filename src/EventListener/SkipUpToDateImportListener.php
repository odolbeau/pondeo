<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Service\Import\ImportGuard;
use App\Service\Import\ImportSource;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Skips an import command whose sources have not changed since its last
 * successful run. `--download` always runs it.
 */
final readonly class SkipUpToDateImportListener
{
    public function __construct(private ImportGuard $importGuard)
    {
    }

    #[AsEventListener]
    public function onCommand(ConsoleCommandEvent $event): void
    {
        $command = $event->getCommand();
        $source = null === $command ? null : $this->sourceOf($command);
        if (null === $command || null === $source || $this->isDownloading($event)) {
            return;
        }

        $name = (string) $command->getName();
        if (!$this->importGuard->isUpToDate($name, $source, $command::class)) {
            return;
        }

        $output = $event->getOutput();
        $command->setCode(static function () use ($output, $name): int {
            $output->writeln(\sprintf('%s: sources unchanged since the last import, skipped.', $name));

            return Command::SUCCESS;
        });
    }

    #[AsEventListener]
    public function onTerminate(ConsoleTerminateEvent $event): void
    {
        $command = $event->getCommand();
        $source = null === $command ? null : $this->sourceOf($command);
        if (null === $command || null === $source || Command::SUCCESS !== $event->getExitCode()) {
            return;
        }

        $name = (string) $command->getName();
        if (!$this->importGuard->isUpToDate($name, $source, $command::class)) {
            $this->importGuard->markDone($name, $source, $command::class);
        }
    }

    private function sourceOf(Command $command): ?ImportSource
    {
        $attributes = new \ReflectionClass($command)->getAttributes(ImportSource::class);

        return [] === $attributes ? null : $attributes[0]->newInstance();
    }

    private function isDownloading(ConsoleCommandEvent $event): bool
    {
        $input = $event->getInput();

        return $input->hasOption('download') && (bool) $input->getOption('download');
    }
}
