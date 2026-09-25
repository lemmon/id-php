<?php

declare(strict_types=1);

namespace Lemmon\Id\Tests;

use Lemmon\Id\Id;
use PHPUnit\Framework\TestCase;

final class IdTest extends TestCase
{
    private static ?string $alphabet = null;

    /** @var array<string, string>|null */
    private static ?array $corrections = null;

    /**
     * Reads Id::ALPHABET via reflection rather than duplicating its literal
     * value here, so this test suite can't silently drift out of sync with
     * the source if the alphabet is ever revised.
     */
    private static function alphabet(): string
    {
        return self::$alphabet ??= (new \ReflectionClass(Id::class))->getConstant('ALPHABET');
    }

    /**
     * @return array<string, string>
     */
    private static function corrections(): array
    {
        return self::$corrections ??= (new \ReflectionClass(Id::class))->getConstant('CORRECTIONS');
    }

    // -- generate() -----------------------------------------------------

    public function testGenerateDefaultsToLengthTen(): void
    {
        self::assertSame(10, strlen(Id::generate()));
    }

    /**
     * @dataProvider lengthProvider
     */
    public function testGenerateReturnsRequestedLength(int $length): void
    {
        self::assertSame($length, strlen(Id::generate($length)));
    }

    public static function lengthProvider(): array
    {
        return [[2], [3], [5], [8], [10], [12], [20]];
    }

    public function testGenerateOnlyUsesAlphabetCharacters(): void
    {
        for ($i = 0; $i < 200; $i++) {
            self::assertMatchesRegularExpression('/^[' . self::alphabet() . ']+$/', Id::generate());
        }
    }

    public function testGenerateProducesSelfVerifyingIds(): void
    {
        for ($i = 0; $i < 500; $i++) {
            $id = Id::generate();
            self::assertTrue(Id::verifyCheck($id), "Generated ID '{$id}' failed its own check.");
        }
    }

    public function testGenerateVariesAcrossCalls(): void
    {
        $ids = [];
        for ($i = 0; $i < 50; $i++) {
            $ids[] = Id::generate();
        }

        self::assertGreaterThan(1, count(array_unique($ids)));
    }

    /**
     * @dataProvider tooShortLengthProvider
     */
    public function testGenerateThrowsBelowMinimumLength(int $length): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Id::generate($length);
    }

    public static function tooShortLengthProvider(): array
    {
        return [[1], [0], [-1]];
    }

    public function testGenerateRetriesUntilBlocklistIsAvoided(): void
    {
        // '3' is common enough (~1 in 3 IDs naively) that this only passes
        // if the retry loop is actually enforcing the blocklist, not just
        // decorative.
        for ($i = 0; $i < 200; $i++) {
            $id = Id::generate(10, ['3']);
            self::assertStringNotContainsString('3', $id);
        }
    }

    public function testGenerateBlocklistMatchingIsCaseInsensitive(): void
    {
        // 'e' is common enough in the alphabet that this only passes if a
        // differently-cased blocklist entry is still matched against the
        // (always-lowercase) generated ID — mirrors
        // testGenerateRetriesUntilBlocklistIsAvoided's use of a common
        // character to prove enforcement, not just presence of the entry.
        for ($i = 0; $i < 200; $i++) {
            $id = Id::generate(10, ['E']);
            self::assertStringNotContainsString('e', $id);
        }
    }

    public function testGenerateEmptyBlocklistDisablesFiltering(): void
    {
        // Mirrors testGenerateRetriesUntilBlocklistIsAvoided's use of '3' as a
        // common, easy-to-hit character: that test proves the retry loop
        // excludes it when blocklisted, so seeing it appear here proves an
        // empty blocklist does NOT exclude it — i.e. filtering is off, not
        // just replaced by a different (e.g. default) word list.
        $ids = array_map(static fn(int $i): string => Id::generate(10, []), range(1, 200));

        self::assertNotEmpty(
            array_filter($ids, static fn(string $id): bool => str_contains($id, '3')),
            'Expected "3" to appear across 200 IDs once filtering is disabled.',
        );
    }

    public function testGenerateThrowsWhenBlocklistCannotBeSatisfied(): void
    {
        // Every alphabet character individually blocked — no candidate can
        // ever pass, so this deterministically exhausts the retry budget.
        $this->expectException(\RuntimeException::class);

        Id::generate(10, str_split(self::alphabet()));
    }

    public function testGenerateMaxConsecutiveDefaultsToUnlimited(): void
    {
        // No assertion on runs themselves (default is "no limit", so runs
        // are simply not guarded against) — this only pins that omitting
        // the argument doesn't throw or otherwise change generate()'s
        // existing default behavior.
        self::assertSame(10, strlen(Id::generate()));
    }

    public function testGenerateEnforcesMaxConsecutiveOfOne(): void
    {
        // maxConsecutive: 1 is the strictest possible setting — no two
        // adjacent characters anywhere in the candidate, including across
        // the body/check-character boundary, may match. Longer IDs and many
        // samples make this a meaningful test of enforcement, not luck.
        for ($i = 0; $i < 200; $i++) {
            $id = Id::generate(20, Id::DEFAULT_BLOCKLIST, 1);
            self::assertDoesNotMatchRegularExpression('/(.)\1/', $id, "'{$id}' contains an adjacent repeat.");
        }
    }

    public function testGenerateEnforcesMaxConsecutiveOfTwo(): void
    {
        // A run of 3 or more identical characters must never appear, but
        // (checked below) a run of exactly 2 remains allowed — proving the
        // limit is enforced at the given value, not silently clamped to 1.
        for ($i = 0; $i < 300; $i++) {
            $id = Id::generate(20, Id::DEFAULT_BLOCKLIST, 2);
            self::assertDoesNotMatchRegularExpression('/(.)\1{2,}/', $id, "'{$id}' contains a run longer than 2.");
        }
    }

    public function testGenerateMaxConsecutiveOfTwoStillAllowsRunsOfTwo(): void
    {
        // Mirrors testGenerateEmptyBlocklistDisablesFiltering's approach:
        // prove the constraint isn't stricter than requested by showing a
        // run of exactly 2 (permitted at maxConsecutive: 2) does appear
        // across enough samples.
        $ids = array_map(static fn(int $i): string => Id::generate(20, Id::DEFAULT_BLOCKLIST, 2), range(1, 300));

        self::assertNotEmpty(
            array_filter($ids, static fn(string $id): bool => preg_match('/(.)\1/', $id) === 1),
            'Expected at least one adjacent repeat across 300 IDs at maxConsecutive: 2.',
        );
    }

    /**
     * @dataProvider tooLowMaxConsecutiveProvider
     */
    public function testGenerateThrowsBelowMinimumMaxConsecutive(int $maxConsecutive): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Id::generate(10, Id::DEFAULT_BLOCKLIST, $maxConsecutive);
    }

    public static function tooLowMaxConsecutiveProvider(): array
    {
        return [[0], [-1]];
    }

    public function testGenerateAcceptsMaxConsecutiveAboveThePcreRepeatCountLimit(): void
    {
        // Regression: an earlier implementation checked runs with a regex
        // quantifier sized directly from $maxConsecutive (e.g. '/(.)\1{100000,}/').
        // PCRE caps repeat counts at 65,535 and errors above it, so any
        // $maxConsecutive that large — trivially larger than any realistic
        // ID — used to break generation instead of being the harmless no-op
        // it should be.
        $id = Id::generate(10, Id::DEFAULT_BLOCKLIST, 100_000);

        self::assertTrue(Id::verifyCheck($id));
    }

    public function testDefaultBlocklistCoversNamedExamples(): void
    {
        $selectedTerms = [
            'creep',
            'dyke',
            'fck',
            'heck',
            'jerk',
            'kkk',
            'meth',
            'nerd',
            'pecker',
            'peen',
            'theft',
            'twerp',
            'wtf',
        ];

        foreach ($selectedTerms as $word) {
            self::assertContains($word, Id::DEFAULT_BLOCKLIST);
        }
    }

    public function testDefaultBlocklistWordsAreSpellableFromAlphabet(): void
    {
        foreach (Id::DEFAULT_BLOCKLIST as $word) {
            self::assertMatchesRegularExpression('/^[' . self::alphabet() . ']+$/', $word);
        }
    }

    public function testAlphabetExcludesA(): void
    {
        // Pin the structural guarantee: with no 'a' in ALPHABET, generate()
        // can never draw words like "hate", "rape", "wank", or "fart" —
        // they no longer need a blocklist entry at all.
        self::assertStringNotContainsString('a', self::alphabet());
    }

    // -- chunk() ------------------------------------------------------------

    public function testChunkInsertsSeparatorEveryChunkSize(): void
    {
        self::assertSame('kwe7-wndx-4t', Id::chunk('kwe7wndx4t'));
    }

    public function testChunkAcceptsCustomSizeAndSeparator(): void
    {
        self::assertSame('kw_e7_wn_dx_4t', Id::chunk('kwe7wndx4t', 2, '_'));
    }

    public function testChunkRoundTripsThroughNormalize(): void
    {
        $id = Id::generate();

        self::assertSame($id, Id::normalize(Id::chunk($id)));
        self::assertSame(strtoupper($id), strtoupper(Id::normalize(Id::chunk(strtoupper($id)))));
    }

    public function testChunkThrowsBelowMinimumSize(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Id::chunk('kwe7wndx4t', 0);
    }

    public function testChunkDoesNotSplitMultibyteCharactersMidSequence(): void
    {
        // str_split() would split by byte, not codepoint, corrupting a
        // multi-byte character. chunk() isn't restricted to canonical
        // (ASCII) IDs, so it must split by grapheme cluster instead.
        $chunked = Id::chunk('héllo', 2);

        self::assertTrue(mb_check_encoding($chunked, 'UTF-8'));
        self::assertSame('hé-ll-o', $chunked);
    }

    public function testChunkKeepsCombiningMarkAttachedToItsBaseCharacter(): void
    {
        // Splitting by codepoint (rather than grapheme cluster) would land
        // the separator between a base character and a following combining
        // mark, corrupting otherwise well-formed UTF-8 — e.g. an 'e'
        // followed by a standalone COMBINING ACUTE ACCENT (U+0301), which
        // together render as a single 'é' grapheme.
        $combining = "xe\u{0301}llo";
        $chunked = Id::chunk($combining, 2);

        self::assertTrue(mb_check_encoding($chunked, 'UTF-8'));
        self::assertSame("xe\u{0301}-ll-o", $chunked);
    }

    public function testChunkFallsBackToByteSplitOnInvalidUtf8(): void
    {
        // No grapheme boundary to respect on invalid UTF-8 — must not
        // throw or silently drop bytes.
        $chunked = Id::chunk("3479c\xFF\xFE", 2);

        self::assertSame("34-79-c\xFF-\xFE", $chunked);
    }

    public function testChunkHandlesChunkSizeLargerThanTheString(): void
    {
        // A $chunkSize past PCRE's repeat-count limit (~65,535) must not
        // trip a bounded \X{1,N} quantifier into a compile failure — \X is
        // used unbounded, with the grouping into chunks done in PHP instead
        // of the regex, so no numeric quantifier is ever at risk here.
        self::assertSame('kwe7wndx4t', Id::chunk('kwe7wndx4t', 100_000));
    }

    public function testChunkHandlesLongIdWithChunkSizeBothPastPcreLimit(): void
    {
        // Regression: capping the quantifier at strlen($id) (a prior
        // implementation) still overflows PCRE's repeat-count limit when
        // $id itself is also longer than that limit — e.g. 40,000 'é'
        // characters (80,000 bytes) chunked at 70,001. The failed match
        // used to fall through to a byte-based str_split() fallback meant
        // only for genuinely invalid UTF-8, corrupting this (valid) input.
        $id = str_repeat('é', 40_000);
        $chunked = Id::chunk($id, 70_001);

        self::assertTrue(mb_check_encoding($chunked, 'UTF-8'));
        self::assertSame($id, $chunked);
    }

    // -- normalize() ------------------------------------------------------

    /**
     * @dataProvider normalizeProvider
     */
    public function testNormalize(string $input, string $expected): void
    {
        self::assertSame($expected, Id::normalize($input));
    }

    public static function normalizeProvider(): array
    {
        return [
            'already canonical' => ['tnx44hdtxk', 'tnx44hdtxk'],
            'uppercase' => ['TNX44HDTXK', 'tnx44hdtxk'],
            'mixed case' => ['Tnx44HdTxk', 'tnx44hdtxk'],
            'surrounding whitespace' => ['  tnx44hdtxk  ', 'tnx44hdtxk'],
            'chunked with spaces' => ['tnx 44 hd txk', 'tnx44hdtxk'],
            'dash separators' => ['tnx-44-hd-txk', 'tnx44hdtxk'],
            'dot separators' => ['tnx.44.hd.txk', 'tnx44hdtxk'],
            'underscore separators' => ['tnx_44_hd_txk', 'tnx44hdtxk'],
            'mixed separators collapse' => ['  TNX-44 HD.TXK ', 'tnx44hdtxk'],
            'lowercase g corrected to 9' => ['gggg', '9999'],
            'lowercase q corrected to 9' => ['qqqq', '9999'],
            'lowercase v corrected to y' => ['vvvv', 'yyyy'],
            'uppercase G corrected to c' => ['GGGG', 'cccc'],
            'uppercase V corrected to y' => ['VVVV', 'yyyy'],
            '1 corrected to 7' => ['1111', '7777'],
            '2 corrected to z' => ['2222', 'zzzz'],
            'corrections combined with case and separators' => ['G-Q_V.1.2', 'cqy7z'],
            'non-breaking space separator' => ["tnx44\u{00A0}hdtxk", 'tnx44hdtxk'],
            'narrow no-break space separator' => ["tnx44\u{202F}hdtxk", 'tnx44hdtxk'],
            'en dash separators' => ["tnx\u{2013}44\u{2013}hd\u{2013}txk", 'tnx44hdtxk'],
            'em dash separator' => ["tnx44\u{2014}hdtxk", 'tnx44hdtxk'],
            'non-breaking hyphen separator' => ["tnx44\u{2011}hdtxk", 'tnx44hdtxk'],
            'minus sign separator' => ["tnx44\u{2212}hdtxk", 'tnx44hdtxk'],
            'zero-width space' => ["tnx44\u{200B}hdtxk", 'tnx44hdtxk'],
            'soft hyphen' => ["tnx44\u{00AD}hdtxk", 'tnx44hdtxk'],
            'leading byte order mark' => ["\u{FEFF}tnx44hdtxk", 'tnx44hdtxk'],
        ];
    }

    public function testNormalizeRetainsInvalidUtf8WhileStillApplyingAsciiCorrections(): void
    {
        // A failed Unicode match skips all separator removal, but subsequent
        // ASCII correction and lowercasing still apply.
        $invalid = "3479c\xFF-G";
        $normalized = Id::normalize($invalid);

        self::assertSame("3479c\xFF-c", $normalized);
        self::assertFalse(Id::isValid($normalized));
    }

    public function testNormalizeLeavesAmbiguousCharactersUncorrected(): void
    {
        // o, 0, i, l, s, u have several plausible sources and are
        // deliberately left alone so isValid() rejects them.
        self::assertSame('o0ilsu', Id::normalize('O0ILSU'));
    }

    public function testNormalizeLeavesALowercaseUncorrected(): void
    {
        // 'a' is excluded for word-avoidance (see Id::ALPHABET), not visual
        // ambiguity — it isn't confusable with anything in ALPHABET, so
        // there's no correction target. isValid() rejects it like any other
        // excluded character.
        self::assertSame('a', Id::normalize('a'));
        self::assertFalse(Id::isValid('a'));
    }

    public function testNormalizeAppliesCaseSpecificCorrections(): void
    {
        // A misread lowercase and uppercase character are different glyph
        // shapes with different look-alikes: lowercase g's loop-and-tail
        // resembles 9, but uppercase G is C plus a small spur. Same source
        // letter, case-appropriate correction, different results.
        self::assertSame('9', Id::normalize('g'));
        self::assertSame('c', Id::normalize('G'));
        self::assertNotSame(Id::normalize('g'), Id::normalize('G'));
    }

    public function testNormalizeLeavesUppercaseQUncorrected(): void
    {
        // Uppercase Q is proportioned nothing like a 9 (unlike lowercase q,
        // which genuinely is), and has no other unambiguous alphabet
        // look-alike, so it is left as 'q' — itself not a valid alphabet
        // character — rather than guessing.
        $normalized = Id::normalize('Q');

        self::assertSame('q', $normalized);
        self::assertFalse(Id::isValid($normalized));
    }

    public function testNormalizeLeavesBUncorrectedInEitherCase(): void
    {
        // b/B is ambiguous between multiple alphabet look-alikes (p, r, d,
        // depending on case and print fidelity), so neither case is
        // auto-corrected.
        self::assertSame('b', Id::normalize('b'));
        self::assertSame('b', Id::normalize('B'));
    }

    // -- CORRECTIONS / ALPHABET invariants ---------------------------------

    /**
     * CORRECTIONS is hand-derived from ALPHABET; if ALPHABET is ever revised
     * and a correction target is forgotten, normalize() would silently
     * "correct" input to a character that's no longer valid, with no signal
     * but a failed isValid() call downstream. Pin the invariant here instead.
     *
     * @dataProvider correctionsProvider
     */
    public function testCorrectionMapsToALiveAlphabetCharacter(string $key, string $value): void
    {
        self::assertStringContainsString(
            $value,
            self::alphabet(),
            "CORRECTIONS maps '{$key}' to '{$value}', which is not (or no longer) in ALPHABET.",
        );
    }

    /**
     * A CORRECTIONS key exists to correct a character that isn't a valid
     * alphabet member (in either case — see the class docblock). If
     * ALPHABET ever grows to include one of these keys, the correction
     * becomes redundant at best and misleading at worst.
     *
     * @dataProvider correctionsProvider
     */
    public function testCorrectionKeyIsExcludedFromAlphabet(string $key, string $value): void
    {
        self::assertStringNotContainsString(
            strtolower($key),
            self::alphabet(),
            "CORRECTIONS key '{$key}' is already a valid ALPHABET character and doesn't need correcting.",
        );
    }

    public static function correctionsProvider(): array
    {
        // Numeric-looking keys ('1', '2') are auto-cast to int by PHP's
        // array-key coercion, so they're cast back to string here to match
        // CORRECTIONS' actual key type.
        return array_map(
            static fn(int|string $key, string $value): array => [(string) $key, $value],
            array_keys(self::corrections()),
            array_values(self::corrections()),
        );
    }

    // -- isValid() --------------------------------------------------------

    public function testIsValidAcceptsCanonicalAlphabetString(): void
    {
        self::assertTrue(Id::isValid('tnx44hdtxk'));
    }

    public function testIsValidRejectsEmptyString(): void
    {
        self::assertFalse(Id::isValid(''));
    }

    public function testIsValidRejectsUppercase(): void
    {
        self::assertFalse(Id::isValid('TNX44HDTXK'));
    }

    /**
     * @dataProvider charactersOutsideAlphabetProvider
     */
    public function testIsValidRejectsCharactersOutsideAlphabet(string $char): void
    {
        self::assertFalse(Id::isValid($char));
    }

    public static function charactersOutsideAlphabetProvider(): array
    {
        // Mixes characters with no correction target at all (genuinely
        // ambiguous — 0, 5, 6, 8, i, l, o, s, u, b — plus 'a', excluded
        // for word-avoidance, not ambiguity) with characters normalize()
        // *can* unambiguously correct (1, 2, g, q, v). isValid() doesn't
        // run corrections itself, so both kinds must be rejected here.
        return array_map(
            static fn(string $c) => [$c],
            str_split('012568abgiloqsuv'),
        );
    }

    public function testIsValidEnforcesExactLengthWhenGiven(): void
    {
        self::assertTrue(Id::isValid('3479c', 5));
        self::assertFalse(Id::isValid('3479c', 4));
        self::assertFalse(Id::isValid('3479c', 6));
    }

    public function testIsValidIgnoresLengthWhenNull(): void
    {
        self::assertTrue(Id::isValid('3479c', null));
        self::assertTrue(Id::isValid('3', null));
    }

    public function testIsValidRejectsTrailingNewline(): void
    {
        // Without pinning $ to the absolute string end, PCRE's $ also
        // matches just before a single trailing "\n", which would let this
        // through despite the newline not being an alphabet character.
        self::assertFalse(Id::isValid("3479c\n"));
    }

    // -- addCheck() / verifyCheck() ----------------------------------------

    public function testAddCheckAppendsAVerifiableCharacter(): void
    {
        $withCheck = Id::addCheck('3479cdefhj');

        self::assertSame(11, strlen($withCheck));
        self::assertTrue(Id::verifyCheck($withCheck));
    }

    public function testAddCheckThrowsOnCharacterOutsideAlphabet(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Id::addCheck('xyz!');
    }

    public function testAddCheckRejectsMultibyteCharacterWithValidUtf8Message(): void
    {
        // The rejection message must not echo a single byte of a multibyte
        // character, or it becomes invalid UTF-8 (breaking e.g. JSON logs).
        try {
            Id::addCheck("3479\u{00E9}");
            self::fail('Expected InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame(1, preg_match('//u', $e->getMessage()));
        }
    }

    public function testAddCheckThrowsOnUppercaseAlphabetCharacters(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Id::addCheck('3479CDEF');
    }

    public function testAddCheckThrowsOnEmptyId(): void
    {
        // A bare check character with no body (what addCheck('') would
        // otherwise return) can never pass verifyCheck(), which rejects
        // anything shorter than 2 characters — so it must be rejected here
        // instead of producing an unverifiable ID.
        $this->expectException(\InvalidArgumentException::class);

        Id::addCheck('');
    }

    public function testVerifyCheckAcceptsKnownGoodId(): void
    {
        self::assertTrue(Id::verifyCheck(Id::addCheck('3479cdefhj')));
    }

    public function testVerifyCheckRejectsAlteredCheckCharacter(): void
    {
        $id = Id::addCheck('3479cdefhj');
        $lastAlphabetIndex = strpos(self::alphabet(), $id[-1]);
        $wrongChar = self::alphabet()[($lastAlphabetIndex + 1) % strlen(self::alphabet())];

        $mutated = substr($id, 0, -1) . $wrongChar;

        self::assertFalse(Id::verifyCheck($mutated));
    }

    public function testVerifyCheckRejectsStringsShorterThanTwo(): void
    {
        self::assertFalse(Id::verifyCheck(''));
        self::assertFalse(Id::verifyCheck('c'));
    }

    public function testVerifyCheckRejectsCharactersOutsideAlphabet(): void
    {
        self::assertFalse(Id::verifyCheck('tnx44hdtx!'));
    }

    public function testVerifyCheckRejectsUppercaseInput(): void
    {
        // verifyCheck expects normalize()d (lowercase) input.
        $id = Id::generate();

        self::assertFalse(Id::verifyCheck(strtoupper($id)));
    }

    // -- Check character: exhaustive substitution-detection proof ---------

    /**
     * Every single-character substitution, at every position, must flip
     * verifyCheck() to false. This is guaranteed independently of the rest
     * of the string's content because the Luhn transform used at each
     * position is a bijection on the alphabet's 20 indices — a changed
     * character always maps to a different transformed value, so the
     * checksum always changes. A handful of structurally distinct base IDs
     * (short, long, boundary length, and one that already contains every
     * alphabet character at least once) is therefore already an exhaustive
     * proof, not a sample.
     *
     * @dataProvider substitutionBaseIdProvider
     */
    public function testCheckCharacterDetectsEverySingleCharacterSubstitution(string $baseId): void
    {
        $id = Id::addCheck($baseId);
        $alphabet = str_split(self::alphabet());

        for ($position = 0; $position < strlen($id); $position++) {
            foreach ($alphabet as $replacement) {
                if ($replacement === $id[$position]) {
                    continue;
                }

                $mutated = $id;
                $mutated[$position] = $replacement;

                self::assertFalse(
                    Id::verifyCheck($mutated),
                    "Substituting position {$position} ('{$id[$position]}' -> '{$replacement}') in '{$id}' went undetected.",
                );
            }
        }
    }

    public static function substitutionBaseIdProvider(): array
    {
        return [
            'minimum length body' => ['3'],
            'two-char body' => ['34'],
            'typical length' => ['3479cdefh'],
            'every alphabet character' => ['3479cdefhjkmnprtwxyz'],
            'longer than default' => ['3479cdefhjkmnprtwxyz3479cdef'],
        ];
    }

    // -- Check character: exhaustive transposition-detection proof --------

    /**
     * Every adjacent transposition must be detected, except the single
     * documented pair 3 <-> z. Adjacent positions always alternate between
     * the checksum's "doubled" and "non-doubled" treatment regardless of
     * where they fall in a longer string, so a 2-character body already
     * exercises both parities for every one of the 20*19 ordered character
     * pairs — this is exhaustive, not a sample.
     */
    public function testCheckCharacterDetectsEveryAdjacentTransposition(): void
    {
        $alphabet = str_split(self::alphabet());
        $undetected = [];

        foreach ($alphabet as $a) {
            foreach ($alphabet as $b) {
                if ($a === $b) {
                    continue;
                }

                $id = Id::addCheck($a . $b);
                $swapped = $b . $a . $id[-1];

                if (Id::verifyCheck($swapped)) {
                    $undetected[] = $a . $b;
                }
            }
        }

        self::assertSame(['3z', 'z3'], $undetected);
    }

    public function testDocumentedTranspositionExceptionIsReallyUndetected(): void
    {
        $id = Id::addCheck('3z');
        $swapped = 'z3' . $id[-1];

        self::assertTrue(Id::verifyCheck($swapped), 'The 3<->z exception should be the one documented gap.');
    }

    public function testJumpTranspositionIsNeverDetected(): void
    {
        // Documented limitation of Luhn-style checks: positions two apart
        // share a weight, so swapping them never changes the checksum.
        $id = Id::addCheck('3cd4efh');
        $swapped = $id;
        $swapped[0] = $id[2];
        $swapped[2] = $id[0];

        self::assertNotSame($id, $swapped);
        self::assertTrue(Id::verifyCheck($swapped));
    }

    public function testAdjacentTranspositionDetectedInsideALongerId(): void
    {
        // Sanity check that the minimal-body proof above generalizes to a
        // realistic full-length ID, not just to 2-character strings.
        $id = Id::addCheck('34cdefhjkm'); // no adjacent '3z'/'z3' pair present
        $swapped = '43cdefhjkm' . $id[-1];

        self::assertNotSame($id, $swapped);
        self::assertFalse(Id::verifyCheck($swapped));
    }
}
