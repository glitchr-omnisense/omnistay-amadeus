<?php

namespace Omnistay\Amadeus;

use Omnistay\Model\Amenity;
use Omnistay\Model\Board;
use Omnistay\Model\BookingStatus;
use Omnistay\Model\PaymentTiming;

/** Amadeus' codes, in the family's words, as its hotel APIs' references list them. */
final class Codes
{
    /** Hotel List's amenities filter (at most three per call), from the family's. */
    public const AMENITIES = [
        'wifi' => 'WIFI',
        'pool' => 'SWIMMING_POOL',
        'spa' => 'SPA',
        'gym' => 'FITNESS_CENTER',
        'air_conditioning' => 'AIR_CONDITIONING',
        'restaurant' => 'RESTAURANT',
        'parking' => 'PARKING',
        'pets' => 'PETS_ALLOWED',
        'accessible' => 'DISABLED_FACILITIES',
        'kitchen' => 'KITCHEN',
    ];

    public static function amenity(Amenity $amenity): ?string
    {
        return self::AMENITIES[$amenity->value] ?? null;
    }

    public static function board(?string $code): ?Board
    {
        return match ($code) {
            'ROOM_ONLY', 'SELF_CATERING' => Board::RoomOnly,
            'BREAKFAST', 'BUFFET_BREAKFAST', 'CARIBBEAN_BREAKFAST', 'CONTINENTAL_BREAKFAST', 'ENGLISH_BREAKFAST', 'FULL_BREAKFAST' => Board::Breakfast,
            'HALF_BOARD', 'DINNER_BED_AND_BREAKFAST' => Board::HalfBoard,
            'FULL_BOARD' => Board::FullBoard,
            'ALL_INCLUSIVE' => Board::AllInclusive,
            default => null,
        };
    }

    public static function payment(?string $type): PaymentTiming
    {
        return match ($type) {
            'DEPOSIT' => PaymentTiming::Deposit,
            'PREPAY' => PaymentTiming::Now,
            default => PaymentTiming::AtProperty,
        };
    }

    /** Hotel Booking's bookingStatus. */
    public static function status(?string $status): BookingStatus
    {
        return match (strtoupper(str_replace(' ', '_', (string) $status))) {
            'CONFIRMED' => BookingStatus::Confirmed,
            'PENDING', 'ON_HOLD' => BookingStatus::OnRequest,
            'CANCELLED' => BookingStatus::Cancelled,
            'UNCONFIRMED', 'DENIED' => BookingStatus::Refused,
            'PAST' => BookingStatus::Completed,
            default => BookingStatus::Pending,
        };
    }
}
