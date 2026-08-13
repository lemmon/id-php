# lemmon/id

**Random IDs made for reliable visual transcription.**

[![Latest Version on Packagist](https://img.shields.io/packagist/v/lemmon/id.svg)](https://packagist.org/packages/lemmon/id)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%5E8.1-blue)](https://php.net)
[![CI](https://github.com/lemmon/id-php/actions/workflows/ci.yml/badge.svg)](https://github.com/lemmon/id-php/actions/workflows/ci.yml)

Most ID generators optimize for entropy density. This one optimizes for the
moment a person must copy an ID from a screen or printed document. It can also
be read over the phone when each character is spelled with code words—for
example Slovak *Peter, Noro, Zuzana* or international *Papa, November, Zulu*.
The alphabet reduces the chance that the person reading the source starts from
the wrong glyph; the spelling words handle ambiguity at the audio end.

Four features support that workflow:

1. **A curated Base20 alphabet** selected to reduce common visual confusion.
2. **A normalizer** that repairs a small set of predictable misreadings.
3. **A check character** that detects every single-character substitution and
   all adjacent transpositions except `3` ↔ `z`.
4. **A short blocklist** that reduces selected unwanted English words in
   customer-facing IDs.

```php
use Lemmon\Id\Id;

$id = Id::generate();                    // "kwe7wndx4t" — canonical, lowercase
echo strtoupper($id);                    // "KWE7WNDX4T" — display form
echo Id::chunk(strtoupper($id));          // "KWE7-WNDX-4T"

// Accepting a transcribed ID back:
$input = Id::normalize('KWE1-WNDX-4T');   // user misread 7 as 1 → repaired

if (!Id::isValid($input, 10) || !Id::verifyCheck($input)) {
    // Reject malformed, wrong-length, or mistyped input.
}
```

No runtime dependencies. One class, six public methods.

## Requirements

- PHP `^8.1`

## Installation

```sh
composer require lemmon/id
```

## The alphabet

```text
3 4 7 9 c d e f h j k m n p r t w x y z
```

The 20-symbol alphabet gives each random body character `log2(20) ≈ 4.32`
bits of entropy. The trailing check character is deterministic and adds no
entropy.

Confusion groups are either removed entirely or represented by one survivor.
Where an excluded character has one plausible target for the case that was
typed, `normalize()` can repair it. Ambiguous cases are rejected instead of
guessed.

| Excluded     | Reason                                                    |
| ------------ | --------------------------------------------------------- |
| `0` `O` `Q`  | commonly confused circular glyphs                         |
| `1` `I` `L`  | commonly confused vertical-stroke glyphs                  |
| `2`          | similar in shape to `Z` (`Z` kept)                        |
| `5` `S`      | similar glyph shapes                                      |
| `6` `G`      | similar hooked-loop shapes                                |
| `8` `B`      | similar stacked-loop shapes                               |
| `U` `V`      | `U` resembles `V`; `V` resembles `Y` (`Y` kept)           |

This is a design judgment, not a typeface-independent guarantee. Applications
should still display IDs in a clear font at a reasonable size. Both lowercase
and uppercase forms were considered so generated IDs remain suitable for
either presentation style.

`A` is excluded for word avoidance rather than visual ambiguity. See
[Avoiding unwanted strings](#avoiding-unwanted-strings).

### Case convention

IDs are generated, stored, and compared in lowercase. Uppercase is a
presentation concern: apply `strtoupper()` in the template or
`text-transform: uppercase` in CSS.

`isValid()` and `verifyCheck()` expect canonical lowercase input. Run
`normalize()` first when accepting a value from a person.

## Formatting for display

`chunk()` groups grapheme clusters to make position and progress easier to
track while copying:

```php
Id::chunk('kwe7wndx4t');           // "kwe7-wndx-4t"
Id::chunk('kwe7wndx4t', 2, ' ');   // "kw e7 wn dx 4t"
```

Chunking is presentational; the stored form has no separators. The default
separator is removed by `normalize()`, so the normal round trip is lossless:

```php
Id::normalize(Id::chunk($id)) === $id;  // true
```

If you choose another separator, ensure that your input handling removes it.

## Normalizing transcriptions

`normalize()` removes supported formatting and returns lowercase input:

```php
Id::normalize('  TNX-44 HD.TXK ');  // "tnx44hdtxk"
```

It removes whitespace—including Unicode whitespace such as a non-breaking
space—and `.`, `_`, and `-`. It then applies these corrections against the
case actually typed:

| Typed | Corrected to | Visual rationale                                           |
| ----- | ------------ | ---------------------------------------------------------- |
| `g`   | `9`          | lowercase loop and tail can resemble `9`                   |
| `q`   | `9`          | lowercase loop and descender can resemble `9`              |
| `v`   | `y`          | a cropped `y` descender can leave a `v` shape              |
| `G`   | `c`          | uppercase `C` and `G` differ mainly by a small spur        |
| `V`   | `y`          | uppercase `V` and `Y` share a forked upper shape           |
| `1`   | `7`          | some handwritten forms of `1` resemble `7`                |
| `2`   | `z`          | digit `2` and letter `Z` have similar shapes               |

Case is significant only while applying corrections. For example,
`Id::normalize('g')` is `'9'`, while `Id::normalize('G')` is `'c'`. If a
misread glyph is entered in a different case from the displayed glyph, its
case-specific repair may not apply; checksum validation should then reject the
result rather than silently accepting another ID.

Characters with several plausible targets—such as `o`, `0`, `i`, `l`, `s`,
`u`, either case of `b`, and uppercase `Q`—are not corrected. They remain in
the output and fail validation. `a` is also left unchanged because it is
excluded for word avoidance, not because it has a correction target.

If the input is not valid UTF-8, Unicode separator removal fails safely and
the original byte sequence is retained for that step. ASCII corrections and
lowercasing still run. The invalid bytes or retained separators subsequently
cause `isValid()` and `verifyCheck()` to return `false`.

Normalization is not validation. For a fixed ten-character format, check both
the expected length and the checksum:

```php
$id = Id::normalize($typed);

if (!Id::isValid($id, 10) || !Id::verifyCheck($id)) {
    // Ask the person to check the transcription.
}
```

## The check character

The last character of every generated ID is a Luhn mod 20 checksum of the
preceding body:

```php
Id::verifyCheck('kwe7wndx4t');   // true
Id::verifyCheck('kwe7wndx4x');   // false
```

The check detects:

- every single-character substitution;
- every adjacent transposition except `3` ↔ `z`.

For strings of the expected length drawn uniformly from the 20-character
alphabet, 1 in 20 has a valid check character by chance. The checksum is an
error-detection feature, not authentication, and adds no protection against
guessing.

## Avoiding unwanted strings

An earlier alphabet contained both `a` and `e`, allowing strings such as
`wank`, `rape`, `hate`, and `fart` to appear as substrings. Removing `a`
makes those examples impossible without filtering. `z` replaces it so the
alphabet remains Base20.

A few selected English terms and initialisms expressible with the remaining
alphabet are in `Id::DEFAULT_BLOCKLIST`:

```php
Id::generate();                                         // default blocklist
Id::generate(10, [...Id::DEFAULT_BLOCKLIST, 'merch']);  // add an app-specific term
Id::generate(10, []);                                   // disable filtering
```

`generate()` rejects and retries, at most 100 times, when any blocklist entry
appears as a substring of the complete candidate, including its check
character. ASCII case is ignored for blocklist entries because generated IDs
are lowercase. Substring matching also catches longer forms—for example,
blocking `peen` also blocks `peened`.

The default blocklist is intentionally short, English-specific, and not a
guarantee against offensive or recognizable strings. Extend or replace it for
your users and languages.

## Avoiding repeating characters

A run of the same character is easy to miscount when reading or copying an
ID — was that two `w`s or three? `generate()`'s optional `$maxConsecutive`
guards against this, off by default:

```php
Id::generate();                        // no limit (default)
Id::generate(10, Id::DEFAULT_BLOCKLIST, 2);   // "kwwe..." ok, "kwwwe..." retried
Id::generate(10, Id::DEFAULT_BLOCKLIST, 1);   // no two adjacent characters may match
```

The check applies to the complete candidate, including the trailing check
character, so a run that straddles the body/check-character boundary is
caught too. Retries share the same 100-attempt budget as the blocklist, and
`generate()` throws `\RuntimeException` if no candidate satisfies both within
that budget.

Use this with caution and common sense. On the 20-character alphabet, low
values shrink the pool of valid candidates — `maxConsecutive: 1` is a strong
constraint, and pairing a strict value with a restrictive `$blocklist` makes
it easier to exhaust the retry budget, especially at short lengths. There's
no built-in floor tying `$maxConsecutive` to `$length`; picking a workable
combination is left to the caller.

## Scope

- **Visual transcription first.** The alphabet reduces glyph confusion when
  reading from a screen or paper. When sending an ID over audio, spell each
  character with code words such as *Peter/Noro/Zuzana* or
  *Papa/November/Zulu*. Bare letter names can still be misheard.
- **Detection, not security.** Do not use these IDs as session tokens, API
  keys, password-reset tokens, or capability URLs.
- **No collision handling.** Enforce uniqueness with a database constraint
  and retry generation after a conflict.
- **Blocklist is not exhaustive.** It handles selected terms, not every word,
  fragment, or language.

## Choosing a length

`generate($length)` returns `$length` characters total: `$length - 1` random
characters followed by one deterministic check character. The approximate
50% birthday-collision thresholds below use the random body space and do not
include application-specific filtering. Stay well below the threshold and
enforce uniqueness on insertion.

| Total length       | Random chars | Entropy   | ~50% collision at |
| ------------------ | ------------ | --------- | ------------------ |
| 6                  | 5            | 21.6 bits | ~2 thousand IDs    |
| 8                  | 7            | 30.3 bits | ~42 thousand IDs   |
| **10** (default)   | 9            | 38.9 bits | ~840 thousand IDs  |
| 12                 | 11           | 47.5 bits | ~17 million IDs    |

IDs are drawn from PHP's CSPRNG through `random_int()`, but at these lengths
they are identifiers, not secrets.

## API

| Method | Description |
| ------ | ----------- |
| `generate(int $length = 10, array $blocklist = self::DEFAULT_BLOCKLIST, ?int $maxConsecutive = null): string` | Generates a lowercase ID with a trailing check character. Throws below length 2, if `$maxConsecutive` is below 1, or after 100 candidates rejected by the blocklist or `$maxConsecutive`. |
| `normalize(string $input): string` | Removes supported separators, applies selected case-aware repairs, and lowercases. It does not validate length or checksum. |
| `isValid(string $id, ?int $length = null): bool` | Checks alphabet membership and, optionally, exact length. It does not verify the checksum. |
| `chunk(string $id, int $chunkSize = 4, string $separator = '-'): string` | Groups grapheme clusters for display. Throws below chunk size 1. |
| `addCheck(string $id): string` | Appends a check character to a non-empty, unchecked body made only from alphabet characters. |
| `verifyCheck(string $id): bool` | Validates alphabet membership and the trailing check character. It does not enforce an application-specific length. |

### Stored-format stability

The alphabet, correction rules, and checksum algorithm are part of the stored
identifier format. Changing the alphabet or checksum can invalidate existing
IDs; changing a correction can alter how historical user input is resolved.
Such changes must be treated as breaking changes once a stable version is
released.

## Prior art

[Crockford Base32](https://www.crockford.com/base32.html) uses a 32-symbol
alphabet, decodes `I`/`L` as `1` and `O` as `0`, excludes `U` to reduce
accidental obscenity, permits hyphen grouping, and defines an optional modulo
37 check symbol. It keeps pairs such as `2`/`Z`, `5`/`S`, and `8`/`B`
distinct. Its random symbols carry 5 bits each, about 0.68 bits more than a
Base20 body character.

The [Open Location Code design](https://github.com/google/open-location-code/blob/26fa64fae1082ff8f9d7ba056b51425b5fefa366/Documentation/Specification/olc_definition.adoc)
(Plus Codes) also uses a 20-symbol alphabet selected to reduce writing errors
and recognizable words. Its designers scored candidate alphabets against
10,000 words from more than 30 languages; the selected alphabet contains no
vowels. Plus Codes have structural validity rules but, as their
[FAQ explains](https://github.com/google/open-location-code/blob/26fa64fae1082ff8f9d7ba056b51425b5fefa366/FAQ.txt#L120-L128),
no checksum. Unlike this library, Open Location Code encodes geographic
coordinates rather than random identifiers.

## Contributing

Bug reports and pull requests are welcome on
[GitHub](https://github.com/lemmon/id-php).

## License

[MIT](LICENSE) © Jakub Pelák
