<?php

declare(strict_types=1);

namespace Lemmon\Id\Tests;

use Lemmon\Id\Id;
use PHPUnit\Framework\TestCase;

final class ChecksumBoundaryTest extends TestCase
{
    public function testCheckCharacterBoundaryHasOnlyDocumentedTranspositionException(): void
    {
        // With a two-character body, varying the first character makes every
        // possible final-body/check-character pair reachable.
        $alphabet = (new \ReflectionClass(Id::class))->getConstant('ALPHABET');
        $characters = str_split($alphabet);
        $undetected = [];

        foreach ($characters as $prefix) {
            foreach ($characters as $lastBodyCharacter) {
                $id = Id::addCheck($prefix . $lastBodyCharacter);

                if ($id[-2] === $id[-1]) {
                    continue;
                }

                $swapped = $id;
                $swapped[-2] = $id[-1];
                $swapped[-1] = $id[-2];

                if (Id::verifyCheck($swapped)) {
                    $undetected[] = $id[-2] . $id[-1];
                }
            }
        }

        sort($undetected);
        self::assertSame(['3z', 'z3'], array_values(array_unique($undetected)));
    }
}
