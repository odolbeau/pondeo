<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Entity\CriterionValue;
use App\Enum\CriterionValueType;
use App\Factory\CriterionFactory;
use App\Factory\UniteUrbaineFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class RestoreSavedStateListenerTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    public function testRankingWithoutFiltersGoesToTheSavedFilters(): void
    {
        $client = $this->clientWithCookies(['pondeo_filters' => 'populationMin=20000&populationMax=150000&trainMaxMinutes%5Bparis%5D=120']);

        $client->request('GET', '/classements/population');

        self::assertResponseRedirects('/classements/population?populationMin=20000&populationMax=150000&trainMaxMinutes%5Bparis%5D=120');
        self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('private'));
        self::assertSame('Cookie', $client->getResponse()->headers->get('Vary'));
    }

    public function testTheRestoredAddressKeepsTheOtherParameters(): void
    {
        $client = $this->clientWithCookies(['pondeo_filters' => 'withStation=1']);

        $client->request('GET', '/classements/population?ordre=inverse&page=2');

        self::assertResponseRedirects('/classements/population?ordre=inverse&page=2&withStation=1');
    }

    public function testAddressWithFiltersWinsOverTheCookie(): void
    {
        $client = $this->clientWithCookies(['pondeo_filters' => 'populationMin=20000']);
        $this->createPopulation();

        $client->request('GET', '/classements/population?populationMax=100000');

        self::assertResponseIsSuccessful();
    }

    public function testNoCookieNoRedirect(): void
    {
        $client = self::createClient();
        $this->createPopulation();

        $client->request('GET', '/classements/population');

        self::assertResponseIsSuccessful();
    }

    public function testInvalidCookieIsIgnored(): void
    {
        $client = $this->clientWithCookies(['pondeo_filters' => 'populationMin=abc&priceType=bogus']);
        $this->createPopulation();

        $client->request('GET', '/classements/population');

        self::assertResponseIsSuccessful();
    }

    public function testUnknownParametersOfTheCookieAreDropped(): void
    {
        $client = $this->clientWithCookies(['pondeo_filters' => 'withStation=1&page=7&evil=1']);

        $client->request('GET', '/classements/population');

        self::assertResponseRedirects('/classements/population?withStation=1');
    }

    public function testMapGoesToTheSavedFiltersToo(): void
    {
        $client = $this->clientWithCookies(['pondeo_filters' => 'restaurantDensityMin=3']);

        $client->request('GET', '/carte');

        self::assertResponseRedirects('/carte?restaurantDensityMin=3');
    }

    public function testRankingsIndexAndOtherPagesAreLeftAlone(): void
    {
        $client = $this->clientWithCookies(['pondeo_filters' => 'withStation=1']);

        $client->request('GET', '/classements');
        self::assertResponseIsSuccessful();

        $client->request('GET', '/criteres');
        self::assertResponseIsSuccessful();
    }

    public function testPersonalRankingGoesToTheSavedImportancesAndFilters(): void
    {
        $client = $this->clientWithCookies(['pondeo_filters' => 'withStation=1', 'pondeo_weights' => 'w%5Bprix_m2%5D=3&w%5Btaux_chomage%5D=5']);

        $client->request('GET', '/mon-classement');

        self::assertResponseRedirects('/mon-classement?withStation=1&w%5Bprix_m2%5D=3&w%5Btaux_chomage%5D=5');
    }

    public function testPersonalRankingKeepsTheImportancesOfTheAddress(): void
    {
        $client = $this->clientWithCookies(['pondeo_weights' => 'w%5Bprix_m2%5D=3']);

        // Every slider at 0 is a choice, not an absence.
        $client->request('GET', '/mon-classement?w[prix_m2]=0');

        self::assertResponseIsSuccessful();
    }

    public function testPersonalRankingAddsTheSavedFiltersToAnAddressWithImportances(): void
    {
        $client = $this->clientWithCookies(['pondeo_filters' => 'withStation=1']);

        $client->request('GET', '/mon-classement?w[prix_m2]=5');

        self::assertResponseRedirects('/mon-classement?w%5Bprix_m2%5D=5&withStation=1');
    }

    public function testInvalidImportancesCookieIsIgnored(): void
    {
        $client = $this->clientWithCookies(['pondeo_weights' => 'w%5Bprix_m2%5D=9']);

        $client->request('GET', '/mon-classement');

        self::assertResponseIsSuccessful();
    }

    /**
     * @param array<string, string> $cookies by name, with the value as the browser sends it
     */
    private function clientWithCookies(array $cookies): KernelBrowser
    {
        $client = self::createClient();
        foreach ($cookies as $name => $value) {
            // The browser keeps the value encoded, and PHP decodes it.
            $client->getCookieJar()->set(new Cookie($name, $value, encodedValue: true));
        }

        return $client;
    }

    private function createPopulation(): void
    {
        $criterion = CriterionFactory::createOne(['code' => 'population', 'label' => 'Population', 'valueType' => CriterionValueType::Integer]);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);
        $entityManager->persist(new CriterionValue($criterion, UniteUrbaineFactory::createOne(), 50_000, 2023));
        $entityManager->flush();
    }
}
