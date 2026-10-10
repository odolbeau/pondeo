<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Factory\PoiFactory;
use App\Factory\UniteUrbaineFactory;
use App\Repository\UniteUrbaineRepository;
use App\Service\PoiUniteUrbaineResolver;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class PoiUniteUrbaineResolverTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function testResolvesTheContainingUniteUrbaine(): void
    {
        $uniteUrbaine = UniteUrbaineFactory::createOne(['boundary' => 'SRID=4326;MULTIPOLYGON(((0 0,0 2,2 2,2 0,0 0)))']);
        $poi = PoiFactory::createOne(['location' => 'SRID=4326;POINT(1 1)']);

        $this->resolver()->resolve($poi);

        self::assertSame($uniteUrbaine->getId()->toRfc4122(), $poi->getUniteUrbaine()?->getId()->toRfc4122());
    }

    public function testPoiOutsideEveryUniteUrbaineGetsNone(): void
    {
        UniteUrbaineFactory::createOne(['boundary' => 'SRID=4326;MULTIPOLYGON(((0 0,0 2,2 2,2 0,0 0)))']);
        $poi = PoiFactory::createOne(['location' => 'SRID=4326;POINT(10 10)']);

        $this->resolver()->resolve($poi);

        self::assertNull($poi->getUniteUrbaine());
    }

    // Built by hand: nothing in the app uses the resolver yet (the future
    // POI import will), so the container removes it as an unused service.
    private function resolver(): PoiUniteUrbaineResolver
    {
        $repository = self::getContainer()->get(UniteUrbaineRepository::class);
        \assert($repository instanceof UniteUrbaineRepository);

        return new PoiUniteUrbaineResolver($repository);
    }
}
