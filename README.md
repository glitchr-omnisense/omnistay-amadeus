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
same APIs are offered under its Enterprise contracts (`host` option). See
[docs/index.md](docs/index.md). License: MIT.
