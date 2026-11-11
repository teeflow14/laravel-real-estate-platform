<?php

namespace Tests\Feature;

use App\Enums\WalletType;
use App\Models\Admin;
use App\Models\ListingPurpose;
use App\Models\Property;
use App\Models\ShortletBooking;
use App\Models\User;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShortletBookingTest extends TestCase
{
    use RefreshDatabase;

    private function shortlet(?User $owner = null): Property
    {
        $purpose = ListingPurpose::firstOrCreate(['slug' => 'short-let'], ['name' => 'Short let']);

        return Property::factory()->published()->create([
            'owner_type' => $owner?->getMorphClass() ?? Admin::class,
            'owner_id' => $owner?->id ?? Admin::factory(),
            'listing_purpose_id' => $purpose->id,
            'price_period' => 'night', 'price_minor' => 15000, 'currency' => 'USD',
        ]);
    }

    private function dates(): array
    {
        return ['check_in' => today()->addDays(5)->toDateString(), 'check_out' => today()->addDays(8)->toDateString()];
    }

    public function test_verified_customer_pays_from_funding_wallet_and_confirms_stay(): void
    {
        config(['plugins.lock_features' => false]);
        $property = $this->shortlet();
        $guest = User::factory()->create();
        $wallets = app(WalletService::class);
        $wallet = $wallets->getOrCreate($guest, WalletType::Funding);
        $wallets->credit($wallet, '500.00', 'test_funding', 'Test funding');
        $route = route('properties.bookings.store', $property->slug);
        $estimate = route('properties.stay-estimate', $property->slug).'?'.http_build_query($this->dates());

        $this->actingAs($guest)->get($estimate)->assertOk()->assertSee('Confirm and pay from Funding Wallet');
        $this->post($route, $this->dates())->assertRedirect();
        $booking = ShortletBooking::query()->firstOrFail();
        $this->assertSame('confirmed', $booking->status);
        $this->assertSame(3, $booking->nights);
        $this->assertSame(45000, $booking->total_minor);
        $this->assertSame('50.00', $wallet->fresh()->balance);
        $this->assertNotNull($booking->wallet_ledger_entry_id);
        $this->assertSame('shortlet_booking', $booking->walletLedgerEntry->type);
        $this->get(route('properties.bookings.show', $booking))->assertOk()->assertSee('Booking confirmed')->assertSee('USD 450.00');
        $this->getJson($estimate)->assertUnprocessable()->assertJsonValidationErrors('check_in');
        $this->from(route('properties.bookings.show', $booking))->get($estimate)
            ->assertRedirect(route('properties.stay-estimate', $property->slug))->assertSessionHasErrors('check_in');
        $this->post($route, $this->dates())->assertSessionHasErrors('check_in');
        $this->assertSame('50.00', $wallet->fresh()->balance);
        $this->assertDatabaseCount('shortlet_bookings', 1);
    }

    public function test_unfunded_or_currency_mismatched_wallet_cannot_book(): void
    {
        config(['plugins.lock_features' => false]);
        $property = $this->shortlet();
        $guest = User::factory()->create();
        $wallet = app(WalletService::class)->getOrCreate($guest, WalletType::Funding);
        $this->actingAs($guest)->post(route('properties.bookings.store', $property->slug), $this->dates())
            ->assertSessionHasErrors('booking');
        $this->assertDatabaseCount('shortlet_bookings', 0);

        $wallet->update(['currency' => 'EUR']);
        $this->get(route('properties.stay-estimate', $property->slug).'?'.http_build_query($this->dates()))
            ->assertOk()->assertSee('same currency')->assertDontSee('Confirm and pay from Funding Wallet');
        $this->post(route('properties.bookings.store', $property->slug), $this->dates())
            ->assertSessionHasErrors('booking');
        $this->assertDatabaseCount('shortlet_bookings', 0);
    }

    public function test_booking_requires_verified_guest_and_enabled_feature(): void
    {
        $property = $this->shortlet();
        $route = route('properties.bookings.store', $property->slug);
        config(['plugins.lock_features' => true]);
        $this->actingAs(User::factory()->create())->post($route, $this->dates())->assertNotFound();

        config(['plugins.lock_features' => false]);
        auth('web')->logout();
        $this->post($route, $this->dates())->assertRedirect();
        $this->actingAs(User::factory()->unverified()->create())->post($route, $this->dates())->assertRedirect();
        $this->assertDatabaseCount('shortlet_bookings', 0);
    }

    public function test_booking_rechecks_blocks_price_and_listing_eligibility(): void
    {
        config(['plugins.lock_features' => false]);
        $property = $this->shortlet();
        $guest = User::factory()->create();
        $wallet = app(WalletService::class)->getOrCreate($guest, WalletType::Funding);
        app(WalletService::class)->credit($wallet, '600.00', 'test_funding', 'Test funding');
        $route = route('properties.bookings.store', $property->slug);
        $property->shortletDateBlocks()->create(['starts_on' => $this->dates()['check_in'], 'ends_on' => $this->dates()['check_out']]);
        $this->actingAs($guest)->post($route, $this->dates())->assertSessionHasErrors('check_in');
        $property->shortletDateBlocks()->delete();
        $property->update(['price_minor' => 20000]);
        $this->post($route, $this->dates())->assertRedirect();
        $this->assertSame(60000, ShortletBooking::query()->firstOrFail()->total_minor);
        $this->assertSame('0.00', $wallet->fresh()->balance);

        $property->update(['price_period' => 'total']);
        $this->post($route, ['check_in' => today()->addDays(10)->toDateString(), 'check_out' => today()->addDays(11)->toDateString()])->assertNotFound();
    }

    public function test_owner_cannot_book_and_another_guest_cannot_view_confirmation(): void
    {
        config(['plugins.lock_features' => false]);
        $owner = User::factory()->create();
        $property = $this->shortlet($owner);
        $this->actingAs($owner)->post(route('properties.bookings.store', $property->slug), $this->dates())
            ->assertSessionHasErrors('booking');
        $booking = ShortletBooking::factory()->create(['property_id' => $property->id, 'guest_id' => User::factory()]);
        $this->get(route('properties.bookings.show', $booking))->assertNotFound();
    }

    public function test_frozen_customer_cannot_pay_for_a_stay(): void
    {
        config(['plugins.lock_features' => false]);
        $property = $this->shortlet();
        $guest = User::factory()->create();
        $wallet = app(WalletService::class)->getOrCreate($guest, WalletType::Funding);
        app(WalletService::class)->credit($wallet, '500.00', 'test_funding', 'Test funding');
        $guest->forceFill(['frozen_at' => now()])->save();

        $this->actingAs($guest)->post(route('properties.bookings.store', $property->slug), $this->dates())
            ->assertSessionHasErrors('booking');
        $this->assertDatabaseCount('shortlet_bookings', 0);
        $this->assertSame('500.00', $wallet->fresh()->balance);
    }

    public function test_owner_cannot_block_a_paid_booking(): void
    {
        config(['plugins.lock_features' => false]);
        $owner = User::factory()->create();
        $property = $this->shortlet($owner);
        $booking = ShortletBooking::factory()->create(['property_id' => $property->id, ...$this->dates()]);
        $this->actingAs($owner)->post(route('customer.shortlet-calendar.store', $property), [
            'starts_on' => $booking->check_in->toDateString(), 'ends_on' => $booking->check_out->toDateString(),
        ])->assertSessionHasErrors('starts_on');
        $this->get(route('customer.shortlet-calendar.show', $property))->assertOk()->assertSee($booking->check_in->toDateString().' booked');
    }
}
