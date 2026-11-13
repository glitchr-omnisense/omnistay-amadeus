# Changelog

## [Unreleased]

## [1.0.0] - 2026-11-13

The first stable version. It requires `omnisense/contracts` `^1.0`, `omnisense/omnistay` `^1.0` with no stability flag, so `composer require omnistay/amadeus` installs it in a project with Composer's default minimum-stability; the 1.x series follows semantic versioning. A tag `vX.Y.Z` is released by `.github/workflows/release.yml` once the package's CI passes on it.

- Hotel List (by city, by point, by hotels), Hotel Search (offers, an offer re-priced), Hotel
  Booking (with a card), the client credentials token kept until it expires, Amadeus' errors 3664
  and 38420 read as unavailable; the test environment by default.
