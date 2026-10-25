<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Controller\Api\UniteUrbaineMapExportController;
use App\Entity\CriterionValue;
use App\Enum\CriterionValueType;
use App\Factory\CriterionFactory;
use App\Factory\UniteUrbaineFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class UniteUrbaineMapExportControllerTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    private const string BOUNDARY = 'SRID=4326;MULTIPOLYGON(((0 0,0 1,1 1,1 0,0 0)))';

    public function testItExportsTheFilteredUnitesUrbainesAsCsv(): void
    {
        $client = self::createClient();

        $many = UniteUrbaineFactory::createOne(['inseeCode' => '00001', 'name' => '=HYPERLINK("x")', 'boundary' => self::BOUNDARY]);
        $few = UniteUrbaineFactory::createOne(['inseeCode' => '00002', 'name' => 'Peu', 'boundary' => self::BOUNDARY]);

        $population = CriterionFactory::createOne(['code' => 'population', 'valueType' => CriterionValueType::Integer]);
        $density = CriterionFactory::createOne(['code' => 'restaurants_pour_1000_hab', 'valueType' => CriterionValueType::Decimal]);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);
        $entityManager->persist(new CriterionValue($population, $many, 20_000, 2023));
        $entityManager->persist(new CriterionValue($population, $few, 10_000, 2023));
        $entityManager->persist(new CriterionValue($density, $many, 3.4, 2025));
        $entityManager->persist(new CriterionValue($density, $few, 1.1, 2025));
        $entityManager->flush();

        $client->request('GET', '/carte/export.csv', ['restaurantDensityMin' => '2']);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/csv; charset=UTF-8');
        self::assertStringContainsString('attachment; filename=pondeo-export-', (string) $client->getResponse()->headers->get('Content-Disposition'));

        $lines = $this->lines($client);
        self::assertSame('"Code INSEE";"Unité urbaine";Département;Population;"Restaurants pour 1000 hab."', $lines[0]);
        // A name that looks like a formula is neutralised; the density has a decimal comma.
        self::assertCount(2, $lines);
        self::assertStringStartsWith('00001;"\'=HYPERLINK(""x"")";', $lines[1]);
        self::assertStringEndsWith(';20000;3,40', $lines[1]);
    }

    public function testItExportsTheAssociationDensityOfAFilteredUnit(): void
    {
        $client = self::createClient();

        $unit = UniteUrbaineFactory::createOne(['inseeCode' => '00001', 'name' => 'Vannes', 'boundary' => self::BOUNDARY]);
        $population = CriterionFactory::createOne(['code' => 'population', 'valueType' => CriterionValueType::Integer]);
        $density = CriterionFactory::createOne(['code' => 'associations_actives_pour_1000_hab', 'valueType' => CriterionValueType::Decimal]);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);
        $entityManager->persist(new CriterionValue($population, $unit, 20_000, 2023));
        $entityManager->persist(new CriterionValue($density, $unit, 31.25, 2026));
        $entityManager->flush();

        $client->request('GET', '/carte/export.csv', ['associationDensityMin' => '20']);

        self::assertResponseIsSuccessful();
        $lines = $this->lines($client);
        self::assertStringEndsWith(';"Associations actives pour 1000 hab."', $lines[0]);
        self::assertStringEndsWith(';20000;31,25', $lines[1]);
    }

    public function testItAcceptsExactlyTheLimitAndRefusesMore(): void
    {
        $client = self::createClient();

        $population = CriterionFactory::createOne(['code' => 'population', 'valueType' => CriterionValueType::Integer]);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);

        for ($i = 0; $i < UniteUrbaineMapExportController::LIMIT; ++$i) {
            $uniteUrbaine = UniteUrbaineFactory::createOne(['inseeCode' => \sprintf('%05d', $i), 'boundary' => self::BOUNDARY]);
            $entityManager->persist(new CriterionValue($population, $uniteUrbaine, 10_000, 2023));
        }

        $entityManager->flush();

        $client->request('GET', '/carte/export.csv');
        self::assertResponseIsSuccessful();
        self::assertCount(UniteUrbaineMapExportController::LIMIT + 1, $this->lines($client));

        $extra = UniteUrbaineFactory::createOne(['inseeCode' => '99999', 'boundary' => self::BOUNDARY]);
        $entityManager->persist(new CriterionValue($population, $extra, 10_000, 2023));
        $entityManager->flush();

        $client->request('GET', '/carte/export.csv');
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('affinez vos filtres', (string) $client->getResponse()->getContent());
    }

    /**
     * @return list<string>
     */
    private function lines(KernelBrowser $client): array
    {
        // The browser captures what a streamed response sends.
        $content = $client->getInternalResponse()->getContent();

        self::assertStringStartsWith("\u{FEFF}", $content);

        return explode("\n", trim(substr($content, 3)));
    }
}
