<?php

declare(strict_types=1);

namespace App\Factory;

use App\Entity\Poi;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Poi>
 */
final class PoiFactory extends PersistentObjectFactory
{
    /**
     * @see https://symfony.com/bundles/ZenstruckFoundryBundle/current/index.html#factories-as-services
     *
     * @todo inject services if required
     */
    public function __construct()
    {
    }

    #[\Override]
    public static function class(): string
    {
        return Poi::class;
    }

    /**
     * @see https://symfony.com/bundles/ZenstruckFoundryBundle/current/index.html#model-factories
     *
     * @todo add your default values here
     */
    #[\Override]
    protected function defaults(): array
    {
        return [
            'category' => PoiCategoryFactory::new(),
            // Fallback point (roughly central France); pass an explicit
            // 'location' when the point actually matters for a test.
            'location' => 'SRID=4326;POINT(2.2 46.6)',
            'name' => self::faker()->text(255),
        ];
    }

    /**
     * @see https://symfony.com/bundles/ZenstruckFoundryBundle/current/index.html#initialization
     */
    #[\Override]
    protected function initialize(): static
    {
        return $this
            // ->afterInstantiate(function(Poi $poi): void {})
        ;
    }
}
