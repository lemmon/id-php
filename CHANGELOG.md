# Changelog

All notable user-facing changes to this project will be documented in this
file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project aims to follow [Semantic Versioning](https://semver.org/spec/v2.0.0.html)
once a stable release is cut.

## [Unreleased]

### Added

- `Id::generate()` gained an optional `$maxConsecutive` parameter, off by
  default, to reject candidates where a character repeats more than that
  many times in a row.

## [0.1.0] - 2026-08-10

### Added

- `Id::generate()` for lowercase random IDs made from a visually curated
  Base20 alphabet, with a mandatory trailing Luhn mod 20 check character.
- `Id::normalize()` for supported separator removal and selected case-aware
  visual-confusion repairs.
- `Id::isValid()` for alphabet and optional exact-length validation.
- `Id::chunk()` for grapheme-aware display grouping.
- `Id::addCheck()` and `Id::verifyCheck()` for checked forms of existing
  alphabet-compatible identifier bodies.
- A short, replaceable default blocklist for selected unwanted English terms
  and initialisms.
- PHP `^8.1` support.

[Unreleased]: https://github.com/lemmon/id-php/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/lemmon/id-php/releases/tag/v0.1.0
