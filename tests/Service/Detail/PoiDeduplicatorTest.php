<?php

declare(strict_types=1);

namespace App\Tests\Service\Detail;

use App\Entity\Poi;
use App\Entity\PoiCategory;
use App\Service\Detail\PoiDeduplicator;
use PHPUnit\Framework\TestCase;

final class PoiDeduplicatorTest extends TestCase
{
    public function testAnEstablishmentFiledInTwoCategoriesIsListedOnceWithBoth(): void
    {
        $seveso = $this->poi('Raffinerie de Donges', 'Site Seveso seuil haut', -2.1, 47.3);
        $polluter = $this->poi('Raffinerie de Donges', 'Établissement pollueur', -2.1, 47.3);

        $result = new PoiDeduplicator()->deduplicate([$seveso, $polluter]);

        self::assertSame([$seveso], $result->pois);
        self::assertSame([$seveso->getId()->toRfc4122() => ['Établissement pollueur']], $result->otherCategoryLabels);
    }

    public function testTheSameNameSpelledAndPlacedSlightlyDifferentlyIsOnePlace(): void
    {
        $first = $this->poi('Raffinerie de Donges', 'Site Seveso seuil haut', -2.1, 47.3);
        // Accents, case and punctuation do not count; the sources place the site a few hundred metres apart.
        $second = $this->poi('RAFFINERIE  DE DONGÈS', 'Établissement pollueur', -2.1, 47.3035);

        self::assertCount(1, new PoiDeduplicator()->deduplicate([$first, $second])->pois);
    }

    public function testDifferentNamesOnTheSameSpotWithAWordInCommonAreOnePlace(): void
    {
        $first = $this->poi('TOTALENERGIES Raffinerie des Flandres', 'Site Seveso seuil haut', 2.2, 51.0);
        $second = $this->poi('Raffinerie des Flandres SAS', 'Établissement pollueur', 2.2001, 51.0001);

        self::assertCount(1, new PoiDeduplicator()->deduplicate([$first, $second])->pois);
    }

    public function testUnrelatedPoisStayApart(): void
    {
        $pois = [
            $this->poi('Usine A', 'Établissement pollueur', 2.2, 51.0),
            // Same spot, nothing in common in the names.
            $this->poi('Dépôt Zeta', 'Site pollué', 2.2, 51.0),
            // Same name, but another place altogether.
            $this->poi('Usine A', 'Établissement pollueur', 2.3, 51.2),
            // A word in common, but too far for different names.
            $this->poi('Usine Alpha', 'Site pollué', 2.201, 51.001),
        ];

        self::assertCount(4, new PoiDeduplicator()->deduplicate($pois)->pois);
    }

    public function testTwoPoisOfTheSameCategoryAreMergedWithoutAnotherLabel(): void
    {
        $first = $this->poi('Usine Beta', 'Établissement pollueur', 2.2, 51.0);
        $second = $this->poi('Usine Beta', 'Établissement pollueur', 2.2, 51.0);

        $result = new PoiDeduplicator()->deduplicate([$first, $second]);

        self::assertSame([$first], $result->pois);
        self::assertSame([], $result->otherCategoryLabels);
    }

    private function poi(string $name, string $categoryLabel, float $longitude, float $latitude): Poi
    {
        return new Poi($name, new PoiCategory('code', $categoryLabel), \sprintf('SRID=4326;POINT(%F %F)', $longitude, $latitude));
    }
}
