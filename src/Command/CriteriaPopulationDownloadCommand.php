<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'app:criteria:population:download',
    description: 'Refresh the vendored population history (resources/geo/population-historique.csv) from INSEE\'s Mélodi API',
)]
final class CriteriaPopulationDownloadCommand extends Command
{
    private const string API_URL = 'https://api.insee.fr/melodi/data/DS_POPULATIONS_HISTORIQUES';

    // Annual estimates only exist from 2006 onward (see plan: older census
    // campaigns are sparser/less comparable, out of scope for now).
    private const int FIRST_YEAR = 2006;

    private const int LAST_YEAR = 2023;

    // The API isn't documented as unlimited — a small pause between the
    // ~72 requests this makes keeps us well under any reasonable rate limit
    // for what is an occasional, manual refresh.
    private const int THROTTLE_MICROSECONDS = 2_100_000;

    public function __construct(private readonly HttpClientInterface $httpClient)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $targetPath = \dirname(__DIR__, 2).'/resources/geo/population-historique.csv';

        $handle = fopen($targetPath, 'w') ?: throw new \RuntimeException("Cannot write {$targetPath}");
        fputcsv($handle, ['code_insee', 'year', 'population'], escape: '\\');

        for ($year = self::FIRST_YEAR; $year <= self::LAST_YEAR; ++$year) {
            $io->writeln(\sprintf('Downloading %d...', $year));
            $rowCount = $this->downloadYear($year, $handle);
            $io->writeln(\sprintf('  %d communes', $rowCount));
        }

        fclose($handle);

        $io->success('Population history refreshed. Review with `git diff resources/geo/` before committing.');

        return Command::SUCCESS;
    }

    /**
     * @param resource $handle
     */
    private function downloadYear(int $year, $handle): int
    {
        $rowCount = 0;
        $page = 1;

        do {
            $response = $this->httpClient->request('GET', self::API_URL, [
                'query' => ['POPREF_MEASURE' => 'PMUN', 'TIME_PERIOD' => $year, 'page' => $page],
            ]);
            /** @var array{observations: list<array{dimensions: array{GEO: string}, measures: array{OBS_VALUE_NIVEAU: array{value: float}}}>, paging: array{isLast?: bool}} $data */
            $data = $response->toArray();

            foreach ($data['observations'] as $observation) {
                $codeInsee = $this->extractCommuneCode($observation['dimensions']['GEO']);
                if (null === $codeInsee) {
                    continue; // Not a commune-level observation — skip.
                }

                fputcsv($handle, [$codeInsee, $year, (int) $observation['measures']['OBS_VALUE_NIVEAU']['value']], escape: '\\');
                ++$rowCount;
            }

            $isLast = $data['paging']['isLast'] ?? false;
            ++$page;

            if (!$isLast) {
                usleep(self::THROTTLE_MICROSECONDS);
            }
        } while (!$isLast);

        return $rowCount;
    }

    /**
     * @param string $geo e.g. "2025-COM-01001"
     */
    private function extractCommuneCode(string $geo): ?string
    {
        if (1 !== preg_match('/^\d{4}-COM-(\w+)$/', $geo, $matches)) {
            return null;
        }

        return $matches[1];
    }
}
