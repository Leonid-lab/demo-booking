<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BookingDestroyTest extends TestCase
{
    use RefreshDatabase;

    public function test_deletes_only_own_booking_with_204(): void
    {
        $user = User::factory()->create();
        $ownBooking = Booking::factory()->for($user)->create();
        $otherBooking = Booking::factory()->create();
        Sanctum::actingAs($user);

        $this->deleteJson('/api/bookings/'.$ownBooking->id)->assertNoContent();

        $this->assertModelMissing($ownBooking);
        $this->assertModelExists($otherBooking);
    }

    public function test_returns_404_for_another_users_booking_without_deleting_it(): void
    {
        $booking = Booking::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->deleteJson('/api/bookings/'.$booking->id)->assertNotFound()->assertJsonMissing(['title' => $booking->title]);

        $this->assertModelExists($booking);
    }

    #[DataProvider('missingBookingIds')]
    public function test_returns_404_for_missing_or_invalid_id(string $id): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->deleteJson('/api/bookings/'.$id)->assertNotFound();

        $this->assertDatabaseEmpty('bookings');
    }

    public static function missingBookingIds(): array
    {
        return [
            'missing' => ['999999'],
            'text' => ['abc'],
            'out of bigint range' => ['99999999999999999999'],
            'negative' => ['-1'],
        ];
    }

    public function test_returns_401_without_token_and_preserves_booking(): void
    {
        $booking = Booking::factory()->create();

        $this->deleteJson('/api/bookings/'.$booking->id)->assertUnauthorized();

        $this->assertModelExists($booking);
    }
}
