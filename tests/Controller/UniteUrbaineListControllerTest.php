<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Enum\Departement;
use App\Factory\UniteUrbaineFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class UniteUrbaineListControllerTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    public function testListsUnitsGroupedByDepartement(): void
    {
        $client = self::createClient();

        UniteUrbaineFactory::createOne(['name' => 'Zeta', 'departement' => Departement::Ain]);
        UniteUrbaineFactory::createOne(['name' => 'Alpha', 'departement' => Departement::Ain]);
        UniteUrbaineFactory::createOne(['name' => 'Beta', 'departement' => Departement::Aisne]);
        UniteUrbaineFactory::createOne(['name' => 'Orphan', 'departement' => null]);

        $client->request('GET', '/unites-urbaines');

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(3, 'section');
        self::assertSelectorTextSame('section:nth-of-type(1) h2', '01 · Ain');
        self::assertSelectorTextSame('section:nth-of-type(1) li:nth-child(1)', 'Alpha');
        self::assertSelectorTextSame('section:nth-of-type(1) li:nth-child(2)', 'Zeta');
        self::assertSelectorTextSame('section:nth-of-type(2) h2', '02 · Aisne');
        self::assertSelectorTextSame('section:nth-of-type(3) h2', 'Sans département');
    }
}
