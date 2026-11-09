<?php

namespace Omnistay\Amadeus\Tests;

use Omnistay\Amadeus\AmadeusFactory;
use Omnistay\Amadeus\AmadeusProvider;
use Omnistay\Exception\InvalidArgumentException;
use Omnistay\Exception\InvalidConfigException;
use Omnistay\Exception\NotSupportedException;
use Omnistay\Exception\ProviderException;
use Omnistay\Exception\UnavailableException;
use Omnistay\Model\Amenity;
use Omnistay\Model\Board;
use Omnistay\Model\BookingRequest;
use Omnistay\Model\BookingStatus;
use Omnistay\Model\Charge;
use Omnistay\Model\Guests;
use Omnistay\Model\PaymentCard;
use Omnistay\Model\PaymentTiming;
use Omnistay\Model\PropertyRef;
use Omnistay\Model\SearchResult;
use Omnistay\Model\Stay;
use Omnistay\Model\StayQuery;
use Omnistay\Model\Traveller;
use Omnistay\Registry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Amadeus' hotel APIs answered from files written by hand after their
 * references (the Hotel List 1.0, Hotel Search 3.0 and Hotel Booking 2.0
 * specifications of amadeus4dev/amadeus-open-api-specification, read on
 * 2026-11-09): no key was used, nothing here was captured from Amadeus.
 * Every request is recorded as sent - method, address with its query,
 * body, the token it carries.
 */
final class AmadeusProviderTest extends TestCase
{
    private const HOST = 'https://test.api.amadeus.com';
    private const SECRET = 'not-a-real-client-secret';

    /** @var list<string> "METHOD url" */
    private array $requests = [];

    /** @var list<array{body: string, authorization: ?string, type: ?string}> */
    private array $sent = [];

    /** @var array<string, array{0: int, 1: string}> pattern on "METHOD url" => [status, fixture] */
    private array $routes = [];

    protected function setUp(): void
    {
        $this->routes = [
            '~^POST '.preg_quote(self::HOST).'/v1/security/oauth2/token$~' => [200, 'token.json'],
            '~/by-city\?~' => [200, 'by-city-nce.json'],
            '~/by-geocode\?~' => [200, 'by-geocode.json'],
            '~/by-hotels\?~' => [200, 'by-hotels.json'],
            '~/v3/shopping/hotel-offers/TSXOJ6LFQ2~' => [200, 'hotel-offer-priced.json'],
            '~/v3/shopping/hotel-offers/GONE~' => [404, ''],
            '~/v3/shopping/hotel-offers\?hotelIds=ZZNCENVX&~' => [200, 'hotel-offers-one.json'],
            '~/v3/shopping/hotel-offers\?hotelIds=FULLFULL&~' => [400, 'error-no-rooms.json'],
            '~/v3/shopping/hotel-offers\?~' => [200, 'hotel-offers-search.json'],
            '~^POST .*/v2/booking/hotel-orders$~' => [201, 'hotel-order-created.json'],
        ];
    }

    public function testTheTokenIsAskedOnceAndCarriedByEveryCall(): void
    {
        $provider = $this->provider();
        $provider->property(new PropertyRef('hotels', 'ARMADAIT'));
        $provider->property(new PropertyRef('hotels', 'ARMADAIT'));

        self::assertSame(['POST '.self::HOST.'/v1/security/oauth2/token', 'GET '.self::HOST.'/v1/reference-data/locations/hotels/by-hotels?hotelIds=ARMADAIT', 'GET '.self::HOST.'/v1/reference-data/locations/hotels/by-hotels?hotelIds=ARMADAIT'], $this->requests);
        self::assertSame('grant_type=client_credentials&client_id=not-a-real-client-id&client_secret='.self::SECRET, $this->sent[0]['body']);
        self::assertSame('application/x-www-form-urlencoded', $this->sent[0]['type']);
        self::assertSame(['Bearer not-a-real-access-token', 'Bearer not-a-real-access-token'], [$this->sent[1]['authorization'], $this->sent[2]['authorization']]);
    }

    public function testATokenIsAskedAgainOnceExpired(): void
    {
        $now = 1_000_000;
        $provider = (new AmadeusFactory($this->http(), static function () use (&$now): int {
            return $now;
        }))->create(['omnistay.name' => 'hotels', 'client_id' => 'not-a-real-client-id', 'client_secret' => self::SECRET]);
        self::assertInstanceOf(AmadeusProvider::class, $provider);

        $provider->property(new PropertyRef('hotels', 'ARMADAIT'));
        $now += 1768;
        $provider->property(new PropertyRef('hotels', 'ARMADAIT'));
        self::assertCount(3, $this->requests, 'still valid 30 seconds before it ends');
        ++$now;
        $provider->property(new PropertyRef('hotels', 'ARMADAIT'));
        self::assertCount(5, $this->requests);
        self::assertStringEndsWith('/oauth2/token', $this->requests[3]);
    }

    public function testACitysHotelsPricedForTheStay(): void
    {
        $page = $this->provider()->search(new StayQuery(Stay::of('2026-12-18', '2026-12-21'), new Guests(2), cityCode: 'nce', radius: 4.2, amenities: [Amenity::Wifi, Amenity::Pool, Amenity::Fireplace, Amenity::Spa, Amenity::Gym], currency: 'EUR', limit: 2));

        self::assertSame([
            'GET '.self::HOST.'/v1/reference-data/locations/hotels/by-city?cityCode=NCE&radius=5&radiusUnit=KM&amenities=WIFI%2CSWIMMING_POOL%2CSPA&hotelSource=ALL',
            'GET '.self::HOST.'/v3/shopping/hotel-offers?hotelIds=ZZNCENVX%2CNNNCE357&adults=2&checkInDate=2026-12-18&checkOutDate=2026-12-21&roomQuantity=1&currency=EUR&bestRateOnly=true',
        ], \array_slice($this->requests, 1));
        self::assertSame([['hotels:ZZNCENVX', 'Hotel de la Promenade', '429.00', '143.00', 0.92], ['hotels:NNNCE357', 'Example Nice Aeroport', '297.00', '99.00', 0.96]], array_map(static fn (SearchResult $r) => [(string) $r->property->ref, $r->property->name, $r->total?->decimal(), $r->nightly()?->decimal(), $r->distance], $page->items));
        self::assertSame(['2', 3], [$page->next, $page->total], 'a third hotel on the next page');
        self::assertSame([43.66665, 7.21465, 'NCE', 'ZZ'], [$page->items[0]->property->location->latitude, $page->items[0]->property->location->longitude, $page->items[0]->property->location->cityCode, $page->items[0]->property->chain]);
    }

    public function testAPointsHotelsWithoutDatesAreNotPriced(): void
    {
        $page = $this->provider()->search(new StayQuery(latitude: 43.6961, longitude: 7.2718, radius: 2));

        self::assertSame(['GET '.self::HOST.'/v1/reference-data/locations/hotels/by-geocode?latitude=43.6961&longitude=7.2718&radius=2&radiusUnit=KM&hotelSource=ALL'], \array_slice($this->requests, 1));
        self::assertSame('Villa des Orangers', $page->items[0]->property->name, 'a name not in capitals is kept');
        self::assertNull($page->items[0]->total);
        self::assertEqualsWithDelta(1.93, $page->items[0]->distance, 0.01, 'miles read as kilometres');
    }

    public function testATextIsNotAPlaceAmadeusReads(): void
    {
        $provider = $this->provider();
        self::assertFalse($provider->capabilities()->reads(new StayQuery(place: 'Nice')));

        $this->expectException(InvalidArgumentException::class);
        $provider->search(new StayQuery(place: 'Nice'));
    }

    public function testAnOfferLineByLine(): void
    {
        $offer = $this->provider()->search(new StayQuery(Stay::of('2026-12-18', '2026-12-21'), new Guests(2), cityCode: 'NCE', limit: 2))->items[0]->offer;
        self::assertNotNull($offer);

        self::assertSame(['TSXOJ6LFQ2', 'BAR', Board::Breakfast, PaymentTiming::AtProperty], [$offer->id, $offer->rateCode, $offer->board, $offer->payment]);
        self::assertSame([['nights', '390.00', false], ['VALUE_ADDED_TAX', '39.00', false]], array_map(static fn (Charge $c) => [$c->code, $c->amount->decimal(), $c->included], $offer->rate->charges));
        self::assertTrue($offer->rate->addsUp());
        self::assertSame(['2026-12-18' => '120.00', '2026-12-19' => '120.00', '2026-12-20' => '150.00'], array_combine(array_map(static fn ($n) => $n->date->format('Y-m-d'), $offer->rate->nights), array_map(static fn ($n) => $n->price->decimal(), $offer->rate->nights)));
        self::assertSame(['A1K', 'Superior Room', 1, 'KING'], [$offer->roomType?->code, $offer->roomType?->name, $offer->roomType?->beds, $offer->roomType?->bedType]);
        self::assertSame(['2026-12-16T18:00:00+01:00', '130.00'], [$offer->cancellation->freeUntil?->format(\DATE_ATOM), $offer->cancellation->penalty?->decimal()]);
    }

    public function testAPropertysOffersAndTheNoRoomsAnswer(): void
    {
        $provider = $this->provider();
        $availability = $provider->availability(new PropertyRef('hotels', 'ZZNCENVX'), Stay::of('2026-12-18', '2026-12-21'), new Guests(2), 'EUR');

        self::assertSame('GET '.self::HOST.'/v3/shopping/hotel-offers?hotelIds=ZZNCENVX&adults=2&checkInDate=2026-12-18&checkOutDate=2026-12-21&roomQuantity=1&currency=EUR&bestRateOnly=false', $this->requests[1]);
        self::assertTrue($availability->available);
        self::assertSame(['Q7MVP2LWD9', '360.00', Board::RoomOnly, PaymentTiming::Now, false], [$availability->cheapest()?->id, $availability->cheapest()?->total()->decimal(), $availability->cheapest()?->board, $availability->cheapest()?->payment, $availability->cheapest()?->cancellation->refundable]);

        $full = $provider->availability(new PropertyRef('hotels', 'FULLFULL'), Stay::of('2026-12-18', '2026-12-21'), new Guests(2));
        self::assertSame([false, 'booked', []], [$full->available, $full->reason, $full->offers]);
    }

    public function testAnOfferRePricedBeforeItIsBooked(): void
    {
        $provider = $this->provider(['language' => 'fr']);
        $offer = $provider->offer('TSXOJ6LFQ2');

        self::assertSame('GET '.self::HOST.'/v3/shopping/hotel-offers/TSXOJ6LFQ2?lang=fr', $this->requests[1]);
        self::assertNotNull($offer);
        self::assertSame(['hotels:ZZNCENVX', '435.60', 'Free until two days before arrival'], [(string) $offer->property, $offer->total()->decimal(), $offer->cancellation->text]);
        self::assertSame(['nights', 'VALUE_ADDED_TAX', 'CITY_TAX'], array_map(static fn (Charge $c) => $c->code, $offer->rate->charges));
        self::assertNull($provider->offer('GONE'), 'expired: 404');
    }

    public function testABookingWithTheCardThatGuaranteesIt(): void
    {
        $provider = $this->provider(['agency_email' => 'bookings@example.org']);
        $offer = $provider->offer('TSXOJ6LFQ2');
        self::assertNotNull($offer);
        $booking = $provider->book(new BookingRequest($offer, [new Traveller('Alice', 'Example', 'alice@example.org', '+33600000000', 'MS')], new PaymentCard('VI', '4151 2897 2247 1370', '2027-08', 'Alice Example'), clientReference: 'S-42'));

        self::assertSame('POST '.self::HOST.'/v2/booking/hotel-orders', $this->requests[2]);
        self::assertSame('application/vnd.amadeus+json', $this->sent[2]['type']);
        self::assertSame('Bearer not-a-real-access-token', $this->sent[2]['authorization']);
        self::assertSame(['data' => [
            'type' => 'hotel-order',
            'guests' => [['tid' => 1, 'title' => 'MS', 'firstName' => 'ALICE', 'lastName' => 'EXAMPLE', 'phone' => '+33600000000', 'email' => 'alice@example.org']],
            'travelAgent' => ['contact' => ['email' => 'bookings@example.org']],
            'roomAssociations' => [['guestReferences' => [['guestReference' => '1']], 'hotelOfferId' => 'TSXOJ6LFQ2']],
            'payment' => ['method' => 'CREDIT_CARD', 'paymentCard' => ['paymentCardInfo' => ['vendorCode' => 'VI', 'cardNumber' => '4151289722471370', 'expiryDate' => '2027-08', 'holderName' => 'ALICE EXAMPLE']]],
        ]], json_decode($this->sent[2]['body'], true));

        self::assertSame(['V0g2VFJaLzIwMjYtMTItMTg=', BookingStatus::Confirmed, 'hotels:ZZNCENVX', '2026-12-18/2026-12-21', '435.60', ['89922710', 'WH6TRZ'], 'S-42'], [$booking->reference, $booking->status, (string) $booking->property, (string) $booking->stay, $booking->total?->decimal(), $booking->confirmations, $booking->clientReference]);
        self::assertSame('Alice Example', mb_convert_case($booking->travellers[0]->name(), MB_CASE_TITLE));
        self::assertNull($provider->booking($booking->reference), 'Hotel Booking 2.0 has no call to read an order again');
    }

    public function testAnOfferGoneMeanwhileIsUnavailable(): void
    {
        $this->routes = ['~oauth2/token~' => [200, 'token.json'], '~hotel-orders~' => [400, 'error-offer-not-found.json']] + $this->routes;
        $provider = $this->provider();
        $offer = $provider->offer('TSXOJ6LFQ2');
        self::assertNotNull($offer);

        try {
            $provider->book(new BookingRequest($offer, [new Traveller('Alice', 'Example')], new PaymentCard('VI', '4151289722471370', '2027-08', 'Alice Example')));
            self::fail('booked');
        } catch (UnavailableException $e) {
            self::assertSame('expired', $e->reason);
            self::assertStringNotContainsString('4151289722471370', (string) $e, 'the card number nowhere in the chain');
        }
    }

    public function testAmadeusBooksWithACardOnly(): void
    {
        $provider = $this->provider();
        $offer = $provider->offer('TSXOJ6LFQ2');
        self::assertNotNull($offer);

        $this->expectException(InvalidArgumentException::class);
        $provider->book(new BookingRequest($offer, [new Traveller('Alice', 'Example')], paymentReference: 'cs_test_1'));
    }

    public function testRefusedCredentialsLeaveNoSecretInTheMessage(): void
    {
        $this->routes = ['~oauth2/token~' => [401, 'error-invalid-client.json']];

        try {
            $this->provider()->property(new PropertyRef('hotels', 'ARMADAIT'));
            self::fail('no exception');
        } catch (ProviderException $e) {
            self::assertStringStartsWith('Amadeus answered 401 for POST /v1/security/oauth2/token.', $e->getMessage());
            self::assertStringNotContainsString(self::SECRET, (string) $e);
        }
    }

    public function testWhatItDoesAndDoesNot(): void
    {
        $registry = new Registry([new AmadeusFactory($this->http())], ['hotels' => ['factory' => 'amadeus', 'options' => ['client_id' => 'x', 'client_secret' => 'y']], 'live' => ['factory' => 'amadeus', 'options' => ['client_id' => 'x', 'client_secret' => 'y', 'environment' => 'production']]]);

        self::assertSame(['search', 'availability', 'book', 'content'], $registry->get('hotels')->capabilities()->contracts());
        self::assertSame(['cityCode', 'point'], $registry->get('hotels')->capabilities()->searchBy);
        self::assertTrue($registry->get('hotels')->capabilities()->sandbox, 'the test environment by default');
        self::assertFalse($registry->get('live')->capabilities()->sandbox);
        self::assertSame('hotels', $registry->get('hotels')->getName());

        try {
            $registry->cancel('hotels');
            self::fail('cancelled');
        } catch (NotSupportedException) {
        }
        $this->expectException(InvalidConfigException::class);
        (new AmadeusFactory($this->http()))->create(['client_id' => 'x']);
    }

    /** @param array<string, mixed> $options */
    private function provider(array $options = []): AmadeusProvider
    {
        $provider = (new AmadeusFactory($this->http()))->create($options + ['omnistay.name' => 'hotels', 'client_id' => 'not-a-real-client-id', 'client_secret' => self::SECRET]);
        self::assertInstanceOf(AmadeusProvider::class, $provider);

        return $provider;
    }

    private function http(): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->requests[] = $method.' '.$url;
            $headers = \is_array($options['normalized_headers'] ?? null) ? $options['normalized_headers'] : [];
            $header = static function (string $name) use ($headers): ?string {
                $values = $headers[$name] ?? null;
                $first = \is_array($values) ? ($values[0] ?? null) : null;

                return \is_string($first) ? substr($first, \strlen($name) + 2) : null;
            };
            $this->sent[] = ['body' => \is_string($options['body'] ?? null) ? $options['body'] : '', 'authorization' => $header('authorization'), 'type' => $header('content-type')];
            foreach ($this->routes as $pattern => [$status, $file]) {
                if (preg_match($pattern, $method.' '.$url)) {
                    return new MockResponse('' === $file ? '' : (string) file_get_contents(__DIR__.'/Fixtures/'.$file), ['http_code' => $status, 'response_headers' => ['content-type' => 'application/vnd.amadeus+json']]);
                }
            }

            return new MockResponse('{"errors":[{"status":404,"title":"not recorded"}]}', ['http_code' => 404]);
        });
    }
}
