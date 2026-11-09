# omnistay/amadeus

## Options

| Option | Default | |
|---|---|---|
| `client_id`, `client_secret` | required | by reference (`%env()%`, a vault); never in code |
| `environment` | `test` | `test`: https://test.api.amadeus.com; `production`: https://api.amadeus.com |
| `host` | - | another address for the same APIs (an Enterprise contract's) |
| `currency`, `language` | - | when the query gives none |
| `hotel_source` | `ALL` | `BEDBANK`, `DIRECTCHAIN`, `ALL` |
| `agency_email` | - | the travel agent's contact in a booking; the guest's when none |

## The calls

| Contract | Call |
|---|---|
| token | `POST /v1/security/oauth2/token` (form: `grant_type=client_credentials`, `client_id`, `client_secret`), kept until 30 seconds before `expires_in` |
| `search()` | `GET /v1/reference-data/locations/hotels/by-city` or `by-geocode` (`radius` in km, `amenities` comma-separated, at most 3, `hotelSource`), then with dates `GET /v3/shopping/hotel-offers` (`hotelIds` comma-separated, at most 20, `adults`, `checkInDate`, `checkOutDate`, `roomQuantity`, `currency`, `bestRateOnly=true`, `lang`); a page is up to 20 hotels |
| `availability()` | `GET /v3/shopping/hotel-offers` for one hotel, `bestRateOnly=false`; the error 3664 (no rooms) is an answer: not available |
| `offer()` | `GET /v3/shopping/hotel-offers/{offerId}` (`lang`); 404: null |
| `book()` | `POST /v2/booking/hotel-orders` (`application/vnd.amadeus+json`): guests, travel agent's e-mail, the room association, `CREDIT_CARD` with the card; 38420 (offer not found) and 3664 throw `UnavailableException` |
| `property()`, `properties()` | `GET /v1/reference-data/locations/hotels/by-hotels` (`hotelIds`, 20 per call) |

Every call carries `Authorization: Bearer <token>`.

## Mapping

- Names and room categories in capitals become titles ("HOTEL DE LA PROMENADE" → "Hotel de la
  Promenade", "SUPERIOR_ROOM" → "Superior Room").
- A rate's lines: `base` (the nights), each tax (`included` kept); its nights from
  `price.variations.changes` (each period's daily price over its dates).
- Cancellation: the first rule's `deadline` (free until), `amount` or `percentage`; `FULL_STAY`
  without a deadline is non-refundable.
- Payment: `GUARANTEE` and `HOLDTIME` → at the property, `DEPOSIT` → deposit, `PREPAY` → now.
- Board: the breakfasts → `Breakfast`, `HALF_BOARD`/`DINNER_BED_AND_BREAKFAST` → `HalfBoard`...
- `bookingStatus`: `CONFIRMED` → confirmed; `PENDING`, `ON_HOLD` → on request; `UNCONFIRMED`,
  `DENIED` → refused; `PAST` → completed.

## Verified, and not

The tests answer from files written by hand after the Self-Service specifications
(amadeus4dev/amadeus-open-api-specification, Hotel List 1.0.4, Hotel Search 3.0.9, Hotel Booking
2.0.1, read on 2026-11-09) and record every request as sent. No key was used and no call reached
Amadeus: its Self-Service portal was closed on 2026-07-17 and no new key can be had there.
