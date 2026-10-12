<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Import\ImportSource;
use App\Service\Import\StationJourneyImporter;
use App\Service\Import\StationJourneySourceDownloader;
use App\Service\Sncf\DateOutOfBoundsException;
use App\Service\Sncf\SncfApiException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:import:pois:stations:journeys',
    description: 'Import the train journeys from each station to Nantes and Paris (resources/geo/trajets-gares.csv)',
    help: <<<'HELP'
        Idempotent upsert — safe to re-run, and works offline. Run <comment>app:import:pois:stations</comment>
        first: each line is attached to its station.

        With <comment>--download</comment>, the vendored file is first refreshed from the SNCF API (token in
        <comment>SNCF_API_TOKEN</comment>, see .env.local). For each station and destination, it keeps the
        journeys of the reference day with the fewest transfers: the direct trains if any, otherwise those with
        one transfer, and so on up to 3.

        It is a long job (thousands of requests) under an API quota. The file is the checkpoint: pairs already
        computed for the same day are skipped, so an interrupted run (Ctrl-C included) resumes where it stopped.
        When the quota is reached, the command waits as long as needed and carries on by itself.

          <comment>--date</comment>     day to read the timetable for (YYYY-MM-DD, within the period the API serves)
          <comment>--station</comment>  only this station (UIC code or station id): a quick check
          <comment>--limit</comment>    stop after this many stations: a pilot run
          <comment>--force</comment>    recompute pairs even if already done for the day. All or nothing: an interrupted
                        forced run cannot be resumed (re-running without --force skips what is "done")

        Run one download at a time: two would overwrite each other's progress.
        HELP,
)]
#[ImportSource('trajets-gares.csv', 'gares-voyageurs.csv')]
final class ImportPoisStationsJourneysCommand extends Command
{
    /** A Tuesday outside school holidays: the day the figures are comparable on. */
    private const string DEFAULT_DAY = '2026-10-13';

    public function __construct(
        private readonly StationJourneyImporter $stationJourneyImporter,
        private readonly StationJourneySourceDownloader $stationJourneySourceDownloader,
    ) {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Refresh the vendored file from the SNCF API before importing')]
        bool $download = false,
        #[Option(description: 'Day to read the timetable for (YYYY-MM-DD)')]
        string $date = self::DEFAULT_DAY,
        #[Option(description: 'With --download: only this station (UIC code or station id)')]
        ?string $station = null,
        #[Option(description: 'With --download: stop after this many stations')]
        ?int $limit = null,
        #[Option(description: 'With --download: recompute pairs already done for the day')]
        bool $force = false,
    ): int {
        $dir = \dirname(__DIR__, 2).'/resources/geo';

        if ($download) {
            $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (false === $day) {
                $io->error(\sprintf('Invalid date "%s": expected YYYY-MM-DD.', $date));

                return Command::INVALID;
            }

            $exitCode = $this->download($io, $dir, $day, $force, $limit, $station);
            if (Command::SUCCESS !== $exitCode) {
                return $exitCode;
            }
        }

        $result = $this->stationJourneyImporter->import($dir.'/trajets-gares.csv');

        $io->success(\sprintf('%d station journeys imported (%d skipped: unknown station or destination).', $result['imported'], $result['skipped']));

        return Command::SUCCESS;
    }

    private function download(SymfonyStyle $io, string $dir, \DateTimeImmutable $day, bool $force, ?int $limit, ?string $station): int
    {
        $bar = null;
        $onStation = static function (int $done, int $total, string $name) use (&$bar, $io): void {
            $bar ??= new ProgressBar($io, $total);
            $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %remaining:6s% left  %message%');
            $bar->setMessage($name);
            $bar->setProgress($done);
        };
        // A line printed over a live progress bar would garble it.
        $progress = static function (string $line) use (&$bar, $io): void {
            $bar?->clear();
            $io->writeln(\sprintf('<comment>[%s]</comment> %s', new \DateTimeImmutable()->format('H:i:s'), $line));
            $bar?->display();
        };

        // SIGINT/SIGTERM: finish the current station, save, stop. Registered
        // by hand because the Console's own signal support needs pcntl for the
        // whole command, which not every environment has.
        if (\function_exists('pcntl_signal') && \function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            foreach ([\SIGINT, \SIGTERM] as $signal) {
                pcntl_signal($signal, $this->stationJourneySourceDownloader->requestStop(...));
            }
        }

        $io->writeln(\sprintf('Reading the timetable of %s from the SNCF API...', $day->format('Y-m-d')));

        try {
            $result = $this->stationJourneySourceDownloader->download($dir, $day, $progress, $onStation, $force, $limit, $station);
        } catch (DateOutOfBoundsException $e) {
            $bar?->clear();
            $io->error($e->getMessage().' Pick a day within the period served by the API (--date).');

            return Command::FAILURE;
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        } catch (SncfApiException $e) {
            $bar?->clear();
            $io->error($e->getMessage().' Progress is saved: re-run to resume.');

            return Command::FAILURE;
        }

        $bar?->finish();
        $io->newLine(2);
        $io->writeln(\sprintf('%d pairs computed, %d already done or not applicable, %d failed; %d API requests.', $result['computed'], $result['skipped'], $result['failed'], $result['requests']));

        if (!$result['completed']) {
            $io->warning('Interrupted. Progress is saved: re-run to resume.');

            return 130;
        }

        $io->note('Journeys refreshed. Review with `git diff --stat resources/geo/` before committing.');

        return Command::SUCCESS;
    }
}
