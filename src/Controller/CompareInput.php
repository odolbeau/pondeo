<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\Validator\Constraints as Assert;

/** Query-string of the comparison page: `villes=56501,44701` lists the INSEE codes to put side by side. */
final readonly class CompareInput
{
    public const int MAX_CITIES = 4;

    public function __construct(
        #[Assert\Regex(pattern: '/^([0-9A-Za-z]{5}(,[0-9A-Za-z]{5}){0,3})?$/', message: 'Indiquez jusqu\'à 4 codes de 5 caractères séparés par des virgules.')]
        public string $villes = '',
    ) {
    }

    /** @return list<string> */
    public function codes(): array
    {
        if ('' === $this->villes) {
            return [];
        }

        return array_values(array_unique(explode(',', $this->villes)));
    }
}
