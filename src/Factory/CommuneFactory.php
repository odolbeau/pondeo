<?php

declare(strict_types=1);

namespace App\Factory;

use App\Entity\Commune;
use App\Enum\Departement;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Commune>
 */
final class CommuneFactory extends PersistentObjectFactory
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
        return Commune::class;
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
            'departement' => self::faker()->randomElement(Departement::cases()),
            'inseeCode' => self::faker()->unique()->numerify('#####'),
            'name' => self::faker()->text(255),
            'uniteUrbaine' => UniteUrbaineFactory::new(),
        ];
    }

    /**
     * @see https://symfony.com/bundles/ZenstruckFoundryBundle/current/index.html#initialization
     */
    #[\Override]
    protected function initialize(): static
    {
        return $this
            // ->afterInstantiate(function(Commune $commune): void {})
        ;
    }
}
