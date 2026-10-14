<?php

declare(strict_types=1);

namespace App\Service\Detail;

use App\Entity\Poi;
use Symfony\Component\String\UnicodeString;

/**
 * Merges the POIs that are the same place filed more than once: a hospital and
 * its emergency department, or one plant found in two sources. Two POIs are
 * the same place when they are very close and their names agree, so that two
 * unrelated POIs next to each other stay apart. The first of the POIs
 * is the one kept; the categories of the others are reported.
 *
 * Done when listing, not when importing: the POIs stay as their sources give
 * them, and can still be counted or filtered per category.
 */
final readonly class PoiDeduplicator
{
    /** Same name: the position may differ by the way each source places the site. */
    private const float SAME_NAME_RADIUS_METERS = 500.0;

    /** Different names, but one word in common: only the same spot counts. */
    private const float SHARED_WORD_RADIUS_METERS = 50.0;

    private const int MIN_WORD_LENGTH = 4;

    /** Words that name a kind of company or place rather than this one. */
    private const array COMMON_WORDS = ['societe', 'groupe', 'centre', 'site', 'usine', 'station', 'ancienne', 'ancien', 'france', 'international', 'industries', 'industrie', 'entreprise', 'etablissements', 'etablissement', 'depot', 'garage', 'musee'];

    // About 1 km in latitude, 700 m in longitude at the north of France: wider than the largest radius, so the neighbouring cells hold every candidate.
    private const float CELL_DEGREES = 0.01;

    /**
     * @param list<Poi> $pois in the order they are to be listed
     */
    public function deduplicate(array $pois): DeduplicatedPois
    {
        /** @var array<string, list<array{poi: Poi, name: string, words: list<string>}>> $cells the kept POIs by cell */
        $cells = [];
        $keptPois = [];
        $otherCategoryLabels = [];

        foreach ($pois as $poi) {
            $name = $this->normalize($poi->getName());
            $words = $this->words($name);
            $latitude = $poi->getLatitude();
            $longitude = $poi->getLongitude();
            $cellX = (int) floor($longitude / self::CELL_DEGREES);
            $cellY = (int) floor($latitude / self::CELL_DEGREES);

            $twin = null;
            for ($x = $cellX - 1; $x <= $cellX + 1 && null === $twin; ++$x) {
                for ($y = $cellY - 1; $y <= $cellY + 1 && null === $twin; ++$y) {
                    foreach ($cells[$x.':'.$y] ?? [] as $candidate) {
                        if ($this->isSamePlace($poi, $name, $words, $candidate)) {
                            $twin = $candidate['poi'];
                            break;
                        }
                    }
                }
            }

            if (null === $twin) {
                $keptPois[] = $poi;
                $cells[$cellX.':'.$cellY][] = ['poi' => $poi, 'name' => $name, 'words' => $words];

                continue;
            }

            $label = $poi->getCategory()->getLabel();
            $id = $twin->getId()->toRfc4122();
            if ($label !== $twin->getCategory()->getLabel() && !\in_array($label, $otherCategoryLabels[$id] ?? [], true)) {
                $otherCategoryLabels[$id][] = $label;
            }
        }

        return new DeduplicatedPois($keptPois, $otherCategoryLabels);
    }

    /**
     * @param list<string>                                       $words
     * @param array{poi: Poi, name: string, words: list<string>} $candidate
     */
    private function isSamePlace(Poi $poi, string $name, array $words, array $candidate): bool
    {
        $distance = $this->distanceInMeters($poi, $candidate['poi']);

        if ($name === $candidate['name']) {
            return $distance <= self::SAME_NAME_RADIUS_METERS;
        }

        return $distance <= self::SHARED_WORD_RADIUS_METERS && [] !== array_intersect($words, $candidate['words']);
    }

    private function normalize(string $name): string
    {
        return trim((string) new UnicodeString($name)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', ' '));
    }

    /**
     * @return list<string> the words that tell this place from another
     */
    private function words(string $normalizedName): array
    {
        // A trailing "s" is dropped, so that a plural is the same word.
        $words = array_map(static fn (string $word): string => rtrim($word, 's'), explode(' ', $normalizedName));

        return array_values(array_filter(
            $words,
            static fn (string $word): bool => \strlen($word) >= self::MIN_WORD_LENGTH && !\in_array($word, self::COMMON_WORDS, true),
        ));
    }

    private function distanceInMeters(Poi $a, Poi $b): float
    {
        $latitudeA = deg2rad($a->getLatitude());
        $latitudeB = deg2rad($b->getLatitude());
        $haversine = sin(($latitudeB - $latitudeA) / 2) ** 2
            + cos($latitudeA) * cos($latitudeB) * sin(deg2rad($b->getLongitude() - $a->getLongitude()) / 2) ** 2;

        return 2 * 6_371_008.8 * asin(sqrt($haversine));
    }
}
