<?php

namespace Omnistay\Amadeus\Tests;

use Omnistay\Amadeus\AmadeusProvider;
use Omnistay\Testing\BareTestCase;

/** omnistay/amadeus's classes load with omnisense/omnistay and Symfony's HTTP client contracts alone. */
final class BareTest extends BareTestCase
{
    protected static function entryClass(): string
    {
        return AmadeusProvider::class;
    }

    protected static function requiredPackages(): array
    {
        return ['symfony/http-client-contracts'];
    }
}
