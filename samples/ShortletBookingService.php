<?php

namespace App\Services\Properties;

use App\Enums\WalletType;
use App\Models\Property;
use App\Models\ShortletBooking;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ShortletBookingService
{
    public function __construct(private ShortletCalendar $calendar, private WalletService $wallets) {}

    /** @param array{check_in: string, check_out: string} $dates */
    public function book(User $guest, Property $property, array $dates): ShortletBooking
    {
        return DB::transaction(function () use ($guest, $property, $dates): ShortletBooking {
            $listing = Property::query()->published()->whereKey($property->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->calendar->eligible($listing) && $listing->availability === 'available', 404);

            if (! $guest->canTransact() || $guest->isFrozen()) {
                throw ValidationException::withMessages(['booking' => 'Bookings are unavailable for this account.']);
            }

            if ($listing->owner_type === $guest->getMorphClass() && (int) $listing->owner_id === (int) $guest->id) {
                throw ValidationException::withMessages(['booking' => 'You cannot book your own listing.']);
            }

            $nights = (int) Carbon::parse($dates['check_in'])->diffInDays($dates['check_out']);
            if ($nights < 1 || $nights > 90) {
                throw ValidationException::withMessages(['check_out' => 'Choose a stay of 90 nights or fewer.']);
            }

            if ($this->calendar->overlaps($listing, $dates['check_in'], $dates['check_out'])) {
                throw ValidationException::withMessages(['check_in' => 'Those dates are unavailable. Choose another stay.']);
            }

            $wallet = $this->wallets->getOrCreate($guest, WalletType::Funding);
            $wallet = Wallet::query()->whereKey($wallet->id)->lockForUpdate()->firstOrFail();
            if ($wallet->status !== 'active' || strtoupper($wallet->currency) !== strtoupper($listing->currency)) {
                throw ValidationException::withMessages(['booking' => 'Funding Wallet and listing currencies must match to book.']);
            }

            if ($listing->price_minor < 1 || $listing->price_minor > intdiv(PHP_INT_MAX, $nights)) {
                throw ValidationException::withMessages(['booking' => 'This listing price cannot be booked.']);
            }

            $totalMinor = $listing->price_minor * $nights;
            $booking = ShortletBooking::query()->create([
                'property_id' => $listing->id, 'guest_id' => $guest->id,
                'check_in' => $dates['check_in'], 'check_out' => $dates['check_out'],
                'nights' => $nights, 'nightly_price_minor' => $listing->price_minor,
                'total_minor' => $totalMinor, 'currency' => strtoupper($listing->currency),
                'status' => 'confirmed',
            ]);
            $amount = sprintf('%d.%02d', intdiv($totalMinor, 100), $totalMinor % 100);
            $entry = $this->wallets->debit($wallet, $amount, 'shortlet_booking', 'Short-let booking: '.$listing->title, source: $booking);
            $booking->update(['wallet_ledger_entry_id' => $entry->id]);

            return $booking;
        });
    }
}
