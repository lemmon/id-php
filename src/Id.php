<?php

declare(strict_types=1);

namespace Lemmon\Id;

final class Id
{
    /**
     * Base20 alphabet selected to reduce common visual confusion in lowercase
     * and uppercase displays. Confusion groups are either removed entirely or
     * represented by one survivor; CORRECTIONS contains only mappings judged
     * unambiguous for the case actually typed.
     *
     * `A` is excluded for word avoidance rather than visual ambiguity. This
     * is a visual model only; audio transmission should use spelling words.
     */
    private const ALPHABET = '3479cdefhjkmnprtwxyz';

    /**
     * Selected case-sensitive misreadings mapped to their intended alphabet
     * character before lowercasing. Ambiguous characters are deliberately
     * absent so validation rejects them rather than guessing.
     */
    private const CORRECTIONS = [
        'g' => '9',
        'q' => '9',
        'v' => 'y',
        'G' => 'c',
        'V' => 'y',
        '1' => '7',
        '2' => 'z',
    ];

    /**
     * Selected unwanted English terms and initialisms expressible by ALPHABET.
     * generate() rejects candidates containing any entry as a substring.
     * The list is intentionally short and not exhaustive; callers may extend
     * or replace it, or pass [] to disable filtering.
     */
    public const DEFAULT_BLOCKLIST = [
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

    /**
     * Generate a user-friendly ID: $length - 1 random characters plus a
     * trailing Luhn mod 20 check character, so every ID self-validates
     * via verifyCheck().
     *
     * Retries (up to 100 times) if the generated ID contains one of
     * $blocklist's entries as a substring — a cheap backstop for what
     * dropping `a` from ALPHABET doesn't already rule out structurally.
     * ASCII case is ignored ('MERCH' and 'merch' both match), since generated
     * IDs are always lowercase. See DEFAULT_BLOCKLIST.
     *
     * $maxConsecutive, off by default, retries within the same budget when
     * the same character repeats more than that many times in a row
     * anywhere in the candidate, including across the body/check-character
     * boundary — a run of identical characters is exactly as easy to
     * miscount when transcribing as it is anywhere else. For example, 2
     * allows "kwwe" but rejects "kwwwe". Use with caution: this shrinks the
     * pool of valid candidates (severely at low values, on a 20-character
     * alphabet), pushes more IDs into retries, and makes it easier to
     * exhaust the 100-attempt budget when combined with a restrictive
     * $blocklist — it's on the caller to pick a sane value for their length,
     * not a mistake-proofed default.
     *
     * Returns lowercase as the canonical form (database, URLs); uppercase it
     * at the presentation layer where desired.
     *
     * @param list<string> $blocklist
     *
     * @throws \InvalidArgumentException if $length is less than 2, or if
     *     $maxConsecutive is less than 1.
     * @throws \RuntimeException if 100 candidates are rejected by $blocklist
     *     or $maxConsecutive.
     */
    public static function generate(
        int $length = 10,
        array $blocklist = self::DEFAULT_BLOCKLIST,
        ?int $maxConsecutive = null,
    ): string {
        if ($length < 2) {
            throw new \InvalidArgumentException(
                'ID length must be at least 2 to include a check character.',
            );
        }

        if ($maxConsecutive !== null && $maxConsecutive < 1) {
            throw new \InvalidArgumentException('maxConsecutive must be at least 1.');
        }

        for ($attempt = 0; $attempt < 100; $attempt++) {
            $result = '';
            for ($i = 0; $i < ($length - 1); $i++) {
                $result .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }

            $id = self::addCheck($result);

            if (self::containsBlockedWord($id, $blocklist)) {
                continue;
            }

            if ($maxConsecutive !== null && self::exceedsMaxConsecutive($id, $maxConsecutive)) {
                continue;
            }

            return $id;
        }

        throw new \RuntimeException(
            'Could not generate an ID satisfying the blocklist and maxConsecutive constraints after 100 attempts.',
        );
    }

    /**
     * Normalize a transcribed ID back to canonical form: strips separators
     * and whitespace (including Unicode space separators like a pasted
     * non-breaking space, Unicode dashes and the minus sign that rich-text
     * editors substitute for `-`, and invisible format characters such as
     * a zero-width space), corrects common misreadings (matched against the
     * case the input was actually typed in — no assumption is made about
     * which case your application displays IDs in), and lowercases the
     * result.
     *
     * If $input isn't valid UTF-8, separator/whitespace stripping is skipped
     * and the original bytes are retained for that step rather than silently
     * collapsing to ''. Corrections and lowercasing still apply; invalid
     * bytes or retained separators are then rejected by validation.
     *
     * Normalization is not validation. For generated IDs, follow it with an
     * expected-length isValid() check and verifyCheck().
     */
    public static function normalize(string $input): string
    {
        // A failed Unicode match retains the original bytes for this step;
        // validation subsequently rejects the invalid bytes or separators.
        $id = preg_replace('/(*UCP)[\s\p{Pd}\p{Cf}\x{2212}._-]+/u', '', $input) ?? $input;
        $id = strtr($id, self::CORRECTIONS);

        return strtolower($id);
    }

    /**
     * Check that an ID consists solely of alphabet characters, optionally
     * enforcing an expected length. This does not verify a check character.
     *
     * Expects normalize()d (lowercase) input.
     */
    public static function isValid(string $id, ?int $length = null): bool
    {
        if ($length !== null && strlen($id) !== $length) {
            return false;
        }

        // The D modifier pins $ to the absolute end of the string; without
        // it, PCRE's $ also matches just before a single trailing "\n",
        // which would let e.g. "3479c\n" pass as valid.
        return preg_match('/^[' . self::ALPHABET . ']+$/D', $id) === 1;
    }

    /**
     * Insert a separator every $chunkSize grapheme clusters for display.
     * This is purely presentational; the canonical/stored form has no
     * separators.
     *
     * The default separator is one normalize() already strips, so a chunked
     * ID round-trips cleanly back through normalize(); pick a different
     * separator and you're responsible for that round trip yourself.
     *
     * @throws \InvalidArgumentException if $chunkSize is less than 1.
     */
    public static function chunk(string $id, int $chunkSize = 4, string $separator = '-'): string
    {
        if ($chunkSize < 1) {
            throw new \InvalidArgumentException('Chunk size must be at least 1.');
        }

        // Unbounded \X avoids PCRE's repeat-count limit; PHP groups the
        // resulting graphemes. Invalid UTF-8 falls back to byte splitting.
        $matches = null;
        $matched = preg_match_all('/\X/u', $id, $matches);
        $graphemes = $matched !== false ? $matches[0] : null;

        if ($graphemes === null) {
            return implode($separator, str_split($id, $chunkSize));
        }

        return implode($separator, array_map(
            static fn(array $chunk): string => implode('', $chunk),
            array_chunk($graphemes, $chunkSize),
        ));
    }

    /**
     * Append a Luhn mod 20 check character to a non-empty, unchecked body
     * containing only canonical alphabet characters.
     *
     * The check character catches every single-character substitution and
     * all adjacent transpositions except 3<->z. A uniformly random string of
     * alphabet characters passes with 1-in-20 odds. As with any Luhn-style
     * check, swapping two characters one position apart (abc -> cba) is
     * never detected, and replacing a doubled pair with another (cc -> dd)
     * occasionally isn't. Detection only — it adds no protection against
     * guessing.
     *
     * @throws \InvalidArgumentException if $id is empty, or contains a
     *     character outside ALPHABET.
     */
    public static function addCheck(string $id): string
    {
        if ($id === '') {
            throw new \InvalidArgumentException(
                'ID must not be empty — a check character alone is not a checkable ID.',
            );
        }

        // Validate up front so the message never echoes a stray byte of a
        // multibyte character, which would make it invalid UTF-8.
        if (!self::isValid($id)) {
            throw new \InvalidArgumentException('ID must contain only alphabet characters.');
        }

        return $id . self::ALPHABET[self::checksum($id)];
    }

    /**
     * Verify the trailing check character of a transcribed ID.
     *
     * Expects normalize()d input and returns false for malformed values. It
     * does not enforce an application-specific expected length.
     */
    public static function verifyCheck(string $id): bool
    {
        if (strlen($id) < 2 || !self::isValid($id)) {
            return false;
        }

        return self::checksum(substr($id, 0, -1)) === strpos(self::ALPHABET, $id[-1]);
    }

    /**
     * Luhn mod 20 checksum over alphabet indices: every second character
     * from the right is doubled (with digit-sum carry), and the check index
     * is what brings the total to a multiple of 20.
     *
     * @return int<0, 19>
     */
    private static function checksum(string $id): int
    {
        $size = strlen(self::ALPHABET);
        $sum = 0;
        $double = true;
        for ($i = strlen($id) - 1; $i >= 0; $i--) {
            $index = strpos(self::ALPHABET, $id[$i]);
            if ($index === false) {
                throw new \InvalidArgumentException("Invalid ID character: {$id[$i]}");
            }
            if ($double) {
                $index *= 2;
                if ($index >= $size) {
                    $index -= $size - 1;
                }
            }
            $sum += $index;
            $double = !$double;
        }

        return ($size - ($sum % $size)) % $size;
    }

    /**
     * @param list<string> $blocklist
     */
    private static function containsBlockedWord(string $id, array $blocklist): bool
    {
        foreach ($blocklist as $word) {
            if ($word !== '' && str_contains($id, strtolower($word))) {
                return true;
            }
        }

        return false;
    }

    /**
     * True if the same character repeats more than $maxConsecutive times in
     * a row anywhere in $id. Counts runs directly instead of building a
     * regex with a $maxConsecutive-sized quantifier: PCRE caps repeat counts
     * at 65,535 and errors above it, which a caller-supplied value could
     * otherwise hit.
     */
    private static function exceedsMaxConsecutive(string $id, int $maxConsecutive): bool
    {
        if ($maxConsecutive >= strlen($id)) {
            return false;
        }

        $run = 1;
        for ($i = 1; $i < strlen($id); $i++) {
            $run = $id[$i] === $id[$i - 1] ? $run + 1 : 1;

            if ($run > $maxConsecutive) {
                return true;
            }
        }

        return false;
    }
}
