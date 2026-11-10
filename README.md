# omnistay/amadeus

[Omnistay](https://github.com/glitchr-omnisense/omnistay)'s provider for hotels through Amadeus'
hotel APIs, under any PHP host:

- **Hotel List** - a city's hotels (`cityCode`) or a point's (`latitude`, `longitude`), with up to
  three amenities as a filter; a hotel's name, point, chain and city code (`ContentInterface`);
- **Hotel Search** - the offers of up to twenty hotels for a stay, priced night by night with their
  taxes, board, payment terms and cancellation policy (`SearchInterface`, `AvailabilityInterface`),
  and an offer re-priced just before booking (`offer()`);
- **Hotel Booking** - a booking guaranteed or paid by a card (`BookInterface`).

```php
$registry = new Omnistay\Registry([new Omnistay\Amadeus\AmadeusFactory($http)], [
    'hotels' => ['factory' => 'amadeus', 'options' => [
        'client_id' => getenv('AMADEUS_CLIENT_ID'),
        'client_secret' => getenv('AMADEUS_CLIENT_SECRET'),
        // 'environment' => 'production',   // the test environment by default: nothing booked is real
    ]],
]);
$registry->search('hotels')->search(new StayQuery(Stay::of('2026-12-18', '2026-12-21'), new Guests(2), cityCode: 'NCE'));
```

Not offered by those APIs: a search by text, a property's calendar, cancelling or reading an
order again (`booking()` answers null). Amadeus closed its Self-Service portal on 2026-07-17; the
same APIs are offered under its Enterprise contracts (`host` option).

Its usage policy is data, in [policy.json](policy.json) (omnisense/contracts' schema, `verifiedAt`
2026-11-10, read in Amadeus' archived developer guides: the Self-Service terms can no longer be
read): 40 calls a second per key in production, 10 on the test environment (the provider gives
that rate there), commercial use on contract, caching, storage, redistribution and credit
unknown. Through omnistay's registry a call over a limit is never made
(`UsageLimitReachedException`), a 429 and its `Retry-After` hold every call until the wait is over,
and a site may lower the rate, never raise it. See
[docs/index.md](docs/index.md). License: MIT.
