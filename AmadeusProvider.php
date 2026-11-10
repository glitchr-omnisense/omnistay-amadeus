<?php

namespace Omnistay\Amadeus;

use Omnisense\Contracts\Policy\DeclaresUsagePolicyInterface;
use Omnisense\Contracts\Policy\UsagePolicy;
use Omnistay\AvailabilityInterface;
use Omnistay\BookInterface;
use Omnistay\Capabilities;
use Omnistay\ContentInterface;
use Omnistay\Exception\InvalidArgumentException;
use Omnistay\Exception\ProviderException;
use Omnistay\Exception\UnavailableException;
use Omnistay\Http\Data;
use Omnistay\Http\JsonClient;
use Omnistay\Model\Availability;
use Omnistay\Model\Booking;
use Omnistay\Model\BookingRequest;
use Omnistay\Model\CancellationPolicy;
use Omnistay\Model\Charge;
use Omnistay\Model\ChargeKind;
use Omnistay\Model\Guests;
use Omnistay\Model\Location;
use Omnistay\Model\Money;
use Omnistay\Model\NightRate;
use Omnistay\Model\Offer;
use Omnistay\Model\Page;
use Omnistay\Model\Property;
use Omnistay\Model\PropertyKind;
use Omnistay\Model\PropertyRef;
use Omnistay\Model\Rate;
use Omnistay\Model\RoomType;
use Omnistay\Model\SearchResult;
use Omnistay\Model\Stay;
use Omnistay\Model\StayQuery;
use Omnistay\Model\Traveller;
use Omnistay\ProviderInterface;
use Omnistay\SearchInterface;

/**
 * Hotels through Amadeus' hotel APIs, as their references describe them
 * (the Self-Service specifications, amadeus4dev/amadeus-open-api-specification):
 *
 * - Hotel List 1.0: GET /v1/reference-data/locations/hotels/by-city (cityCode),
 *   by-geocode (latitude, longitude) and by-hotels (hotelIds): a place's
 *   hotels, a hotel's name, point, chain and city code;
 * - Hotel Search 3.0: GET /v3/shopping/hotel-offers (up to 20 hotelIds, the
 *   dates, adults, rooms, currency) and GET /v3/shopping/hotel-offers/{offerId}
 *   (an offer re-priced, its policies whole);
 * - Hotel Booking 2.0: POST /v2/booking/hotel-orders (the offer, the guests,
 *   the card that guarantees or pays it).
 *
 * It searches by IATA city code or by point (not by a text), prices a search
 * that has dates, books with a card. There is no cancellation nor retrieval
 * of an order in those APIs: CancelInterface is not implemented and
 * booking() answers null. Every call carries the access token (Auth).
 *
 * Its usage policy is policy.json, beside this class (POLICY): 40 calls a
 * second in production, 10 on the test environment (usagePolicy() gives
 * that rate when the provider is configured there), commercial use on
 * contract. Amadeus decommissioned its Self-Service offer in 2026 (keys
 * disabled on 2026-07-17): only its Enterprise APIs remain, on contract,
 * reached through the "host" option.
 */
final class AmadeusProvider implements ProviderInterface, SearchInterface, AvailabilityInterface, BookInterface, ContentInterface, DeclaresUsagePolicyInterface
{
    /** Amadeus' usage policy, as read on the day it says (provenance.verifiedAt). */
    public const POLICY = __DIR__.'/policy.json';

    /** The test environment's rate, in place of production's when the provider is configured there. */
    public const TEST_RATE = ['max' => 10, 'window' => 'second', 'scope' => 'key', 'operations' => [], 'note' => 'The test environment: 10 transactions a second, no more than one request every 100 ms.'];

    private static ?UsagePolicy $declared = null;

    private ?UsagePolicy $policy = null;

    /** Hotel Search takes at most 20 hotels per call. */
    public const MAX_HOTELS = 20;

    public function __construct(
        private readonly JsonClient $json,
        private readonly Auth $auth,
        private readonly string $host,
        private readonly string $name = 'amadeus',
        private readonly bool $sandbox = true,
        private readonly ?string $currency = null,
        private readonly ?string $language = null,
        private readonly string $hotelSource = 'ALL',
        private readonly ?string $agencyEmail = null,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getTitle(): string
    {
        return 'Amadeus hotels';
    }

    public function usagePolicy(): UsagePolicy
    {
        $declared = self::$declared ??= UsagePolicy::fromFile(self::POLICY);

        // In place of production's 40 a second, not beside it: a policy holding two limits that count
        // the same calls could not be tightened again by the site (UsagePolicy::tighten()).
        return $this->policy ??= $this->sandbox ? UsagePolicy::fromArray(['rateLimits' => [self::TEST_RATE]] + $declared->toArray()) : $declared;
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            search: true, availability: true, book: true, content: true,
            searchBy: ['cityCode', 'point'], kinds: [PropertyKind::Hotel], pricedSearch: true, cardPayment: true, sandbox: $this->sandbox,
            terms: ['https://developers.amadeus.com/legal/terms-of-service'],
            policy: $this->usagePolicy(),
        );
    }

    public function search(StayQuery $query): Page
    {
        $filters = array_filter([
            'radius' => (int) min(300, max(1, ceil($query->radius))),
            'radiusUnit' => 'KM',
            'amenities' => implode(',', \array_slice(array_values(array_filter(array_map(Codes::amenity(...), $query->amenities))), 0, 3)) ?: null,
            'hotelSource' => $this->hotelSource,
        ], static fn ($value) => null !== $value);
        if ($query->hasPoint()) {
            $list = $this->get('/v1/reference-data/locations/hotels/by-geocode', ['latitude' => $query->latitude, 'longitude' => $query->longitude] + $filters);
        } elseif (null !== $query->cityCode) {
            $list = $this->get('/v1/reference-data/locations/hotels/by-city', ['cityCode' => strtoupper($query->cityCode)] + $filters);
        } else {
            throw new InvalidArgumentException('Amadeus searches by a city code or a point.');
        }
        $hotels = $list?->list('data') ?? [];
        $offset = max(0, (int) $query->page);
        $size = min($query->limit, self::MAX_HOTELS);
        $slice = \array_slice($hotels, $offset, $size);
        $next = $offset + $size < \count($hotels) ? (string) ($offset + $size) : null;
        $properties = [];
        foreach ($slice as $hotel) {
            if (null !== $property = $this->hotel($hotel)) {
                $properties[$property->ref->id] = $property;
            }
        }
        if (null === $query->stay || [] === $properties) {
            return new Page(array_values(array_map(static fn (Property $p) => new SearchResult($p, distance: self::distance($hotels, $p)), $properties)), $next, \count($hotels));
        }

        $results = [];
        foreach ($this->offers(array_keys($properties), $query->stay, $query->guests, $query->rooms, $query->currency, true, $query->language) as $id => [$hotel, $offers]) {
            $property = $properties[$id] ?? $hotel;
            usort($offers, static fn (Offer $a, Offer $b) => $a->total()->amount <=> $b->total()->amount);
            if ([] !== $offers) {
                $results[] = new SearchResult($property, $offers[0]->total(), $query->stay->count(), true, $offers[0], self::distance($hotels, $property));
            }
        }

        return new Page($results, $next, \count($hotels));
    }

    public function availability(PropertyRef $property, Stay $stay, Guests $guests, ?string $currency = null): Availability
    {
        try {
            $offers = $this->offers([$property->id], $stay, $guests, 1, $currency, false, null)[$property->id][1] ?? [];
        } catch (ProviderException $e) {
            // 3664: NO ROOMS AVAILABLE AT REQUESTED PROPERTY - an answer, not a failure.
            if (str_contains($e->getMessage(), '3664')) {
                return new Availability($property, $stay, $guests, false, reason: 'booked');
            }
            throw $e;
        }

        return new Availability($property, $stay, $guests, [] !== $offers, $offers, [] === $offers ? 'booked' : null);
    }

    public function offer(string $id, ?string $language = null): ?Offer
    {
        $data = $this->get('/v3/shopping/hotel-offers/'.rawurlencode($id), ['lang' => $language ?? $this->language]);
        if (null === $data) {
            return null;
        }
        $hotel = $data->data('data')->data('hotel');
        $offer = $data->data('data')->list('offers')[0] ?? null;

        return null === $offer ? null : $this->readOffer($offer, $this->ref((string) $hotel->string('hotelId')));
    }

    public function book(BookingRequest $request): Booking
    {
        $card = $request->card ?? throw new InvalidArgumentException('Amadeus books a hotel with a card that guarantees or pays it (BookingRequest::$card).');
        $guests = [];
        foreach ($request->travellers as $i => $traveller) {
            $guests[] = array_filter(['tid' => $i + 1, 'title' => $traveller->title, 'firstName' => mb_strtoupper($traveller->firstName), 'lastName' => mb_strtoupper($traveller->lastName), 'phone' => $traveller->phone, 'email' => $traveller->email], static fn ($value) => null !== $value);
        }
        $body = ['data' => array_filter([
            'type' => 'hotel-order',
            'guests' => $guests,
            'travelAgent' => ['contact' => ['email' => $this->agencyEmail ?? $request->contact()->email ?? '']],
            'roomAssociations' => [['guestReferences' => [['guestReference' => '1']], 'hotelOfferId' => $request->offer->id]],
            'payment' => ['method' => 'CREDIT_CARD', 'paymentCard' => ['paymentCardInfo' => ['vendorCode' => $card->vendorCode, 'cardNumber' => preg_replace('~\D~', '', $card->number), 'expiryDate' => $card->expiry, 'holderName' => mb_strtoupper($card->holder)]]],
        ])];
        try {
            $data = $this->json->request('POST', $this->host.'/v2/booking/hotel-orders', ['json' => $body], $this->auth->headers() + ['Content-Type' => 'application/vnd.amadeus+json']);
        } catch (ProviderException $e) {
            // 38420 OFFER NOT FOUND, 3664 NO ROOMS AVAILABLE: the nights went to someone else.
            if (str_contains($e->getMessage(), '38420') || str_contains($e->getMessage(), '3664')) {
                throw new UnavailableException(\sprintf('Amadeus: the offer for %s is no longer available.', $request->offer->property), str_contains($e->getMessage(), '38420') ? 'expired' : 'booked', $e);
            }
            throw $e;
        }
        $order = $data?->data('data') ?? Data::of([]);
        $booking = $order->list('hotelBookings')[0] ?? throw ProviderException::answered('Amadeus', 'POST /v2/booking/hotel-orders', 201, 'No hotel booking in the order.');
        $offer = $booking->data('hotelOffer');
        $stay = new Stay((string) $offer->string('checkInDate'), (string) $offer->string('checkOutDate'));
        $confirmations = [...$booking->strings('hotelProviderInformation', 'confirmationNumber'), ...$order->strings('associatedRecords', 'reference')];
        $travellers = array_map(static fn (Data $g) => new Traveller((string) $g->string('firstName'), (string) $g->string('lastName'), $g->string('email'), $g->string('phone'), $g->string('title')), $order->list('guests'));
        $price = $offer->data('price');
        $currency = $price->string('currency');

        return new Booking(
            (string) ($order->string('id') ?? $booking->string('id')),
            Codes::status($booking->string('bookingStatus')),
            $this->ref((string) ($booking->data('hotel')->string('hotelId') ?? $request->offer->property->id)),
            $stay,
            new Guests(max(1, $offer->data('guests')->int('adults') ?? $request->offer->guests->adults)),
            null !== $currency && null !== $price->string('total') ? Money::fromDecimal((string) $price->string('total'), $currency) : $request->offer->total(),
            $confirmations,
            $travellers ?: $request->travellers,
            $request->offer,
            $this->cancellation($offer->data('policies'), $currency),
            null,
            $request->clientReference,
            $data?->toArray() ?? [],
        );
    }

    /** Hotel Booking 2.0 answers the order when it is made, and has no call to read it again: null. */
    public function booking(string $reference): ?Booking
    {
        return null;
    }

    public function property(PropertyRef $property, ?string $language = null): ?Property
    {
        return $this->properties([$property], $language)[0] ?? null;
    }

    public function properties(array $properties, ?string $language = null): array
    {
        $found = [];
        foreach (array_chunk(array_values(array_unique(array_map(static fn (PropertyRef $ref) => $ref->id, $properties))), self::MAX_HOTELS) as $ids) {
            foreach ($this->get('/v1/reference-data/locations/hotels/by-hotels', ['hotelIds' => implode(',', $ids)])?->list('data') ?? [] as $hotel) {
                if (null !== $property = $this->hotel($hotel)) {
                    $found[] = $property;
                }
            }
        }

        return $found;
    }

    /**
     * Hotel Search's offers for these hotels, by hotel id: the hotel as it
     * answers it, and its offers.
     *
     * @param list<string> $ids
     *
     * @return array<string, array{0: Property, 1: list<Offer>}>
     */
    private function offers(array $ids, Stay $stay, Guests $guests, int $rooms, ?string $currency, bool $bestRateOnly, ?string $language): array
    {
        $data = $this->get('/v3/shopping/hotel-offers', [
            'hotelIds' => implode(',', \array_slice($ids, 0, self::MAX_HOTELS)),
            'adults' => $guests->adults,
            'checkInDate' => $stay->checkIn->format('Y-m-d'),
            'checkOutDate' => $stay->checkOut->format('Y-m-d'),
            'roomQuantity' => $rooms,
            'currency' => $currency ?? $this->currency,
            'bestRateOnly' => $bestRateOnly ? 'true' : 'false',
            'lang' => $language ?? $this->language,
        ]);
        $found = [];
        foreach ($data?->list('data') ?? [] as $item) {
            $hotel = $item->data('hotel');
            $property = $this->hotel($hotel);
            if (null === $property || false === $item->bool('available')) {
                continue;
            }
            $offers = array_values(array_filter(array_map(fn (Data $offer) => $this->readOffer($offer, $property->ref), $item->list('offers'))));
            $found[$property->ref->id] = [$property, $offers];
        }

        return $found;
    }

    private function hotel(Data $hotel): ?Property
    {
        $id = $hotel->string('hotelId');
        if (null === $id) {
            return null;
        }
        $latitude = $hotel->float('geoCode.latitude') ?? $hotel->float('latitude');
        $longitude = $hotel->float('geoCode.longitude') ?? $hotel->float('longitude');

        return new Property(
            $this->ref($id),
            self::title((string) ($hotel->string('name') ?? $id)),
            PropertyKind::Hotel,
            new Location($latitude, null === $latitude ? null : $longitude, countryCode: $hotel->string('address.countryCode'), cityCode: $hotel->string('iataCode') ?? $hotel->string('cityCode')),
            chain: $hotel->string('chainCode'),
        );
    }

    private function readOffer(Data $offer, PropertyRef $property): ?Offer
    {
        $id = $offer->string('id');
        $checkIn = $offer->string('checkInDate');
        $checkOut = $offer->string('checkOutDate');
        $price = $offer->data('price');
        $currency = $price->string('currency');
        $total = $price->string('total') ?? $price->string('sellingTotal');
        if (null === $id || null === $checkIn || null === $checkOut || null === $currency || null === $total) {
            return null;
        }
        $stay = Stay::of($checkIn, $checkOut);
        $totalMoney = Money::fromDecimal($total, $currency);
        $base = null !== $price->string('base') ? Money::fromDecimal((string) $price->string('base'), $currency) : null;
        $charges = [new Charge(ChargeKind::Nights, $base ?? $totalMoney, code: 'nights')];
        foreach ($price->list('taxes') as $tax) {
            if (null !== $amount = $tax->string('amount')) {
                $charges[] = new Charge(ChargeKind::Tax, Money::fromDecimal($amount, $tax->string('currency') ?? $currency), $tax->string('description'), (bool) $tax->bool('included'), $tax->string('code'));
            }
        }
        $nights = [];
        foreach ($price->list('variations.changes') as $change) {
            $nightly = $change->string('total') ?? $change->string('base');
            $from = $change->string('startDate');
            $to = $change->string('endDate');
            if (null === $nightly || null === $from || null === $to || $to <= $from) {
                continue;
            }
            foreach (Stay::of($from, $to)->dates() as $date) {
                $nights[] = new NightRate($date, Money::fromDecimal($nightly, $change->string('currency') ?? $currency));
            }
        }
        $room = $offer->data('room');
        $estimated = $room->data('typeEstimated');
        $description = $room->string('description.text');

        return new Offer(
            $id,
            $property,
            $stay,
            new Guests(max(1, $offer->data('guests')->int('adults') ?? 1), array_values(array_map('intval', array_filter((array) ($offer->data('guests')->get('childAges') ?? []), 'is_numeric')))),
            new Rate($totalMoney, $charges, $nights),
            $this->cancellation($offer->data('policies'), $currency),
            null !== $room->string('type') ? new RoomType((string) $room->string('type'), self::title($estimated->string('category') ?? (string) $room->string('type')), $description, beds: $estimated->int('beds'), bedType: $estimated->string('bedType')) : null,
            Codes::board($offer->string('boardType')),
            Codes::payment($offer->string('policies.paymentType')),
            max(1, $offer->int('roomQuantity') ?? 1),
            rateCode: $offer->string('rateCode'),
            description: $description,
        );
    }

    private function cancellation(Data $policies, ?string $currency): CancellationPolicy
    {
        $rule = $policies->list('cancellations')[0] ?? null;
        if (null === $rule) {
            return new CancellationPolicy(text: $policies->string('cancellation.description.text'));
        }
        $deadline = $rule->string('deadline');
        $freeUntil = null;
        if (null !== $deadline) {
            try {
                $freeUntil = new \DateTimeImmutable($deadline);
            } catch (\Exception) {
            }
        }
        $amount = $rule->string('amount');
        $text = $rule->string('description.text');
        if ('FULL_STAY' === $rule->string('type') && null === $freeUntil) {
            return CancellationPolicy::nonRefundable($text);
        }

        return new CancellationPolicy(
            true,
            $freeUntil,
            null !== $amount && null !== $currency ? Money::fromDecimal($amount, $currency) : null,
            null !== $rule->string('percentage') ? (int) round((float) $rule->string('percentage')) : ('FULL_STAY' === $rule->string('type') ? 100 : null),
            $text,
        );
    }

    /** @param array<string, string|int|float|bool|null> $query */
    private function get(string $path, array $query): ?Data
    {
        return $this->json->get($this->host.$path, $query, $this->auth->headers());
    }

    private function ref(string $id): PropertyRef
    {
        return new PropertyRef($this->name, $id);
    }

    /** Hotel names and room categories come in capitals ("HOTEL DE PARIS", "SUPERIOR_ROOM"): written as a title, short words kept small. */
    private static function title(string $name): string
    {
        $name = str_replace('_', ' ', $name);
        if ($name !== mb_strtoupper($name)) {
            return $name;
        }
        $words = explode(' ', mb_convert_case(mb_strtolower($name), MB_CASE_TITLE));
        foreach ($words as $i => $word) {
            if ($i > 0 && \in_array(mb_strtolower($word), ['de', 'du', 'des', 'la', 'le', 'les', 'et', 'and', 'of', 'the', 'by', 'a', 'au', 'aux', 'en'], true)) {
                $words[$i] = mb_strtolower($word);
            }
        }

        return implode(' ', $words);
    }

    /** @param list<Data> $hotels Hotel List's answers, which carry the distance to the point asked */
    private static function distance(array $hotels, Property $property): ?float
    {
        foreach ($hotels as $hotel) {
            if ($property->ref->id === $hotel->string('hotelId')) {
                $value = $hotel->float('distance.value');

                return null === $value ? null : ('MILE' === $hotel->string('distance.unit') ? $value * 1.609344 : $value);
            }
        }

        return null;
    }
}
