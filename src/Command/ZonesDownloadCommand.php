<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'app:zones:download',
    description: 'Refresh the vendored administrative zone reference files (resources/geo/)',
)]
final class ZonesDownloadCommand extends Command
{
    // Hardcoded on purpose — one-off annual refresh, not a configurable system.
    private const array SOURCES = [
        'regions.geojson' => 'https://raw.githubusercontent.com/gregoiredavid/france-geojson/master/regions-version-simplifiee.geojson',
        'departements.geojson' => 'https://raw.githubusercontent.com/gregoiredavid/france-geojson/master/departements-version-simplifiee.geojson',
        'communes.geojson' => 'https://raw.githubusercontent.com/gregoiredavid/france-geojson/master/communes-version-simplifiee.geojson',
        // "Communes et villes de France" (data.gouv.fr) — stable resource
        // redirect, always resolves to the publisher's current version.
        'communes-epci.csv' => 'https://www.data.gouv.fr/api/1/datasets/r/c63fd0b1-7987-46f6-b779-8b3ed889090c',
    ];

    public function __construct(private readonly HttpClientInterface $httpClient)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $targetDir = \dirname(__DIR__, 2).'/resources/geo';

        foreach (self::SOURCES as $filename => $url) {
            $io->writeln(\sprintf('Downloading <info>%s</info>...', $filename));

            try {
                $response = $this->httpClient->request('GET', $url);
                $content = $response->getContent();
            } catch (TransportException $e) {
                $io->error(\sprintf('Failed to download %s from %s: %s', $filename, $url, $e->getMessage()));

                return Command::FAILURE;
            }

            file_put_contents($targetDir.'/'.$filename, $content);
        }

        $io->success('All zone reference files refreshed. Review with `git diff resources/geo/` before committing.');

        return Command::SUCCESS;
    }
}
