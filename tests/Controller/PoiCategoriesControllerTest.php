<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Factory\PoiCategoryFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PoiCategoriesControllerTest extends WebTestCase
{
    public function testListsTheCategoriesUnderTheirParent(): void
    {
        $client = self::createClient();

        $parent = PoiCategoryFactory::createOne(['code' => 'hospital', 'label' => 'Hôpital ou clinique']);
        $clinic = PoiCategoryFactory::createOne(['code' => 'hospital_clinic', 'label' => 'Clinique', 'parent' => $parent]);
        PoiCategoryFactory::createOne(['code' => 'hospital_clinic_private', 'label' => 'Clinique privée', 'parent' => $clinic]);
        PoiCategoryFactory::createOne(['code' => 'nuclear_power_plant', 'label' => 'Centrale nucléaire']);

        $client->request('GET', '/categories-poi');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Catégories de POI');
        self::assertSelectorTextContains('main > div > ul > li:last-child > ul', 'Clinique');
        self::assertSelectorTextContains('main > div > ul > li:last-child > ul > li > ul', 'Clinique privée');
        self::assertSelectorTextContains('main > div > ul', 'Centrale nucléaire');
    }

    public function testTheFooterLinksToThePage(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');

        self::assertSelectorExists('footer a[href="/categories-poi"]');
    }
}
