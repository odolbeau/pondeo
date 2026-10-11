<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Enum\CriterionValueType;
use App\Factory\CriterionFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class CriteriaListControllerTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    public function testListsCriteriaByGroupWithTheirDescription(): void
    {
        $client = self::createClient();

        CriterionFactory::createOne(['code' => 'prix_m2', 'label' => 'Prix au m²', 'valueType' => CriterionValueType::Decimal, 'groupLabel' => 'Immobilier']);
        CriterionFactory::createOne(['code' => 'population', 'label' => 'Population', 'valueType' => CriterionValueType::Integer, 'groupLabel' => 'Démographie']);
        CriterionFactory::createOne(['code' => 'inconnu', 'label' => 'Sans texte', 'valueType' => CriterionValueType::Integer, 'groupLabel' => null]);

        $client->request('GET', '/criteres');

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(3, 'section');
        self::assertSelectorTextSame('section:nth-of-type(1) h2', 'Démographie');
        self::assertSelectorTextSame('section:nth-of-type(2) h2', 'Immobilier');
        self::assertSelectorTextSame('section:nth-of-type(3) h2', 'Autres');
        self::assertSelectorTextContains('section:nth-of-type(1) dd', "nombre d'habitants");
        self::assertSelectorTextContains('section:nth-of-type(1) dd:last-child', 'Source : INSEE');
        self::assertSelectorExists('a[href="/classements/population"]');
    }
}
