<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Office;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BookingStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_booking_with_token_owner_and_ignores_untrusted_attributes(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 10)->setTime(12, 0));
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $room = Room::factory()->create();
        $title = "O'Reilly'); DROP TABLE bookings; --";
        Log::spy();
        Log::shouldReceive('channel')->with('bookings')->andReturnSelf();

        $response = $this->withToken($user->createToken('test')->plainTextToken)
            ->postJson('/api/bookings', array_replace($this->payload($room), [
                'user_id' => $otherUser->id,
                'id' => 999999,
                'title' => $title,
                'created_at' => '2000-01-01T00:00:00Z',
                'updated_at' => '2000-01-01T00:00:00Z',
                'unexpected' => 'ignored',
            ]));

        $response->assertCreated()
            ->assertJsonPath('user_id', $user->id)
            ->assertJsonPath('room_id', $room->id)
            ->assertJsonPath('title', $title)
            ->assertJsonPath('starts_at', '2026-11-02T07:00:00.000000Z')
            ->assertJsonPath('ends_at', '2026-11-02T08:00:00.000000Z')
            ->assertJsonPath('created_at', '2026-11-10T12:00:00.000000Z')
            ->assertJsonPath('updated_at', '2026-11-10T12:00:00.000000Z')
            ->assertJsonMissingPath('unexpected');
        $this->assertNotSame(999999, $response->json('id'));
        $this->assertDatabaseCount('bookings', 1);
        $this->assertDatabaseHas('bookings', [
            'id' => $response->json('id'),
            'user_id' => $user->id,
            'room_id' => $room->id,
            'title' => $title,
            'starts_at' => '2026-11-02 07:00:00',
            'ends_at' => '2026-11-02 08:00:00',
            'created_at' => '2026-11-10 12:00:00',
        ]);
        $booking = Booking::findOrFail($response->json('id'));
        $this->assertTrue($booking->user->is($user));
        $this->assertTrue($booking->room->is($room));
        Log::shouldHaveReceived('withContext')->once()->with(['booking_id' => $booking->id]);
        Log::shouldHaveReceived('channel')->once()->with('bookings');
        Log::shouldHaveReceived('info')->once()->with('Booking created');
    }

    #[DataProvider('validIntervals')]
    public function test_accepts_office_local_intervals_and_persists_utc(
        string $timezone,
        string $start,
        string $end,
        string $startUtc,
        string $endUtc,
    ): void {
        $this->travelTo(now()->setDate(2026, 11, 10)->setTime(12, 0));
        $room = Room::factory()->for(Office::factory()->state(['timezone' => $timezone]))->create();
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/bookings', array_replace($this->payload($room), [
            'starts_at' => $start,
            'ends_at' => $end,
            'title' => str_repeat('я', 255),
        ]));

        $response->assertCreated()
            ->assertJsonPath('starts_at', $startUtc.'.000000Z')
            ->assertJsonPath('ends_at', $endUtc.'.000000Z');
        $this->assertDatabaseCount('bookings', 1);
        $this->assertDatabaseHas('bookings', [
            'starts_at' => str_replace('T', ' ', $startUtc),
            'ends_at' => str_replace('T', ' ', $endUtc),
        ]);
        $booking = Booking::findOrFail($response->json('id'));
        $this->assertSame($startUtc, $booking->starts_at->format('Y-m-d\TH:i:s'));
        $this->assertSame($endUtc, $booking->ends_at->format('Y-m-d\TH:i:s'));
    }

    public static function validIntervals(): array
    {
        return [
            'minimum at opening' => ['Europe/Moscow', '2026-11-02T09:00:00+03:00', '2026-11-02T09:15:00+03:00', '2026-11-02T06:00:00', '2026-11-02T06:15:00'],
            'maximum duration' => ['Europe/Moscow', '2026-11-02T10:00:00+03:00', '2026-11-02T14:00:00+03:00', '2026-11-02T07:00:00', '2026-11-02T11:00:00'],
            'closing boundary' => ['Europe/Moscow', '2026-11-02T19:45:00+03:00', '2026-11-02T20:00:00+03:00', '2026-11-02T16:45:00', '2026-11-02T17:00:00'],
            'remaining quarter hours' => ['Europe/Moscow', '2026-11-02T10:15:00+03:00', '2026-11-02T10:30:00+03:00', '2026-11-02T07:15:00', '2026-11-02T07:30:00'],
            'UTC' => ['Europe/Moscow', '2026-11-02T06:00:00Z', '2026-11-02T06:15:00Z', '2026-11-02T06:00:00', '2026-11-02T06:15:00'],
            'zero offset' => ['Europe/Moscow', '2026-11-02T06:00:00+00:00', '2026-11-02T06:15:00+00:00', '2026-11-02T06:00:00', '2026-11-02T06:15:00'],
            'fractional hour offset' => ['Europe/Moscow', '2026-11-02T11:45:00+05:45', '2026-11-02T12:00:00+05:45', '2026-11-02T06:00:00', '2026-11-02T06:15:00'],
            'negative offset' => ['Europe/Moscow', '2026-11-02T00:30:00-05:30', '2026-11-02T00:45:00-05:30', '2026-11-02T06:00:00', '2026-11-02T06:15:00'],
            'client minutes off grid but office on grid' => ['Europe/Moscow', '2026-11-02T10:07:00+04:07', '2026-11-02T10:22:00+04:07', '2026-11-02T06:00:00', '2026-11-02T06:15:00'],
            'Novosibirsk opening' => ['Asia/Novosibirsk', '2026-11-02T02:00:00Z', '2026-11-02T02:15:00Z', '2026-11-02T02:00:00', '2026-11-02T02:15:00'],
            'Novosibirsk closing' => ['Asia/Novosibirsk', '2026-11-02T19:45:00+07:00', '2026-11-02T20:00:00+07:00', '2026-11-02T12:45:00', '2026-11-02T13:00:00'],
            'previous client date' => ['Asia/Novosibirsk', '2026-11-01T21:00:00-05:00', '2026-11-01T21:15:00-05:00', '2026-11-02T02:00:00', '2026-11-02T02:15:00'],
            'past weekend' => ['Europe/Moscow', '2020-01-04T10:00:00+03:00', '2020-01-04T11:00:00+03:00', '2020-01-04T07:00:00', '2020-01-04T08:00:00'],
            'minutes without seconds' => ['Europe/Moscow', '2026-11-02T09:00+03:00', '2026-11-02T09:15+03:00', '2026-11-02T06:00:00', '2026-11-02T06:15:00'],
            'compact offset' => ['Europe/Moscow', '2026-11-02T09:00:00+0300', '2026-11-02T09:15:00+0300', '2026-11-02T06:00:00', '2026-11-02T06:15:00'],
            'hour offset' => ['Europe/Moscow', '2026-11-02T09:00:00+03', '2026-11-02T09:15:00+03', '2026-11-02T06:00:00', '2026-11-02T06:15:00'],
            'zero fractions' => ['Europe/Moscow', '2026-11-02T09:00:00.0+03:00', '2026-11-02T09:15:00.000000+03:00', '2026-11-02T06:00:00', '2026-11-02T06:15:00'],
        ];
    }

    #[DataProvider('overlappingIntervals')]
    public function test_returns_422_for_overlaps_after_normalizing_offsets(string $start, string $end): void
    {
        $existing = Booking::factory()->create([
            'starts_at' => '2026-11-02 07:00:00',
            'ends_at' => '2026-11-02 08:00:00',
        ]);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/bookings', array_replace($this->payload($existing->room), [
            'starts_at' => $start,
            'ends_at' => $end,
        ]))->assertUnprocessable()->assertJsonValidationErrors(['starts_at' => 'Комната занята в выбранное время.']);

        $this->assertDatabaseCount('bookings', 1);
        $this->assertModelExists($existing);
    }

    public static function overlappingIntervals(): array
    {
        return [
            'identical in another offset' => ['2026-11-02T12:30:00+05:30', '2026-11-02T13:30:00+05:30'],
            'left overlap' => ['2026-11-02T09:30:00+03:00', '2026-11-02T10:30:00+03:00'],
            'right overlap' => ['2026-11-02T10:30:00+03:00', '2026-11-02T11:30:00+03:00'],
            'inside' => ['2026-11-02T10:15:00+03:00', '2026-11-02T10:45:00+03:00'],
            'containing' => ['2026-11-02T09:30:00+03:00', '2026-11-02T11:30:00+03:00'],
        ];
    }

    public function test_accepts_adjacent_intervals_and_independent_rooms(): void
    {
        $room = Room::factory()->create();
        $otherRoom = Room::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/bookings', $this->payload($room))->assertCreated();
        $this->postJson('/api/bookings', array_replace($this->payload($room), [
            'starts_at' => '2026-11-02T06:00:00Z',
            'ends_at' => '2026-11-02T07:00:00Z',
        ]))->assertCreated();
        $this->postJson('/api/bookings', array_replace($this->payload($room), [
            'starts_at' => '2026-11-02T08:00:00Z',
            'ends_at' => '2026-11-02T09:00:00Z',
        ]))->assertCreated();
        $this->postJson('/api/bookings', $this->payload($otherRoom))->assertCreated();

        $this->assertDatabaseCount('bookings', 4);
        $this->assertSame(3, $room->bookings()->count());
        $this->assertSame(1, $otherRoom->bookings()->count());
    }

    #[DataProvider('invalidIntervals')]
    public function test_returns_422_for_invalid_time_rules(string $start, string $end, string $field, string $message): void
    {
        $room = Room::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/bookings', array_replace($this->payload($room), [
            'starts_at' => $start,
            'ends_at' => $end,
        ]))->assertUnprocessable()->assertJsonValidationErrors([$field => $message]);

        $this->assertDatabaseEmpty('bookings');
    }

    public static function invalidIntervals(): array
    {
        $duration = 'Длительность должна быть от 15 до 240 минут с шагом 15 минут.';
        $hours = 'Бронь должна целиком укладываться в 09:00–20:00 одного дня офиса.';
        $grid = 'Время должно быть на сетке 15 минут по часовому поясу офиса, без секунд и долей секунды.';

        return [
            'shorter than minimum' => ['2026-11-02T10:00:00+03:00', '2026-11-02T10:14:00+03:00', 'ends_at', $duration],
            'longer than maximum' => ['2026-11-02T10:00:00+03:00', '2026-11-02T14:15:00+03:00', 'ends_at', $duration],
            'zero duration' => ['2026-11-02T10:00:00+03:00', '2026-11-02T10:00:00+03:00', 'ends_at', $duration],
            'negative duration' => ['2026-11-02T10:00:00+03:00', '2026-11-02T09:45:00+03:00', 'ends_at', $duration],
            'not a multiple of fifteen' => ['2026-11-02T10:00:00+03:00', '2026-11-02T10:20:00+03:00', 'ends_at', $duration],
            'before opening' => ['2026-11-02T08:45:00+03:00', '2026-11-02T09:00:00+03:00', 'starts_at', $hours],
            'after closing' => ['2026-11-02T19:45:00+03:00', '2026-11-02T20:15:00+03:00', 'starts_at', $hours],
            'starting at closing' => ['2026-11-02T20:00:00+03:00', '2026-11-02T20:15:00+03:00', 'starts_at', $hours],
            'local midnight' => ['2026-11-02T23:45:00+03:00', '2026-11-03T00:15:00+03:00', 'starts_at', $hours],
            'office minutes off grid' => ['2026-11-02T11:00:00+04:07', '2026-11-02T12:00:00+04:07', 'starts_at', $grid],
            'equal nonzero seconds' => ['2026-11-02T10:00:01+03:00', '2026-11-02T11:00:01+03:00', 'starts_at', $grid],
            'equal microseconds' => ['2026-11-02T10:00:00.000001+03:00', '2026-11-02T11:00:00.000001+03:00', 'starts_at', $grid],
            'end seconds' => ['2026-11-02T10:00:00+03:00', '2026-11-02T11:00:01+03:00', 'ends_at', $duration],
            'end microseconds' => ['2026-11-02T10:00:00+03:00', '2026-11-02T11:00:00.000001+03:00', 'ends_at', $grid],
        ];
    }

    #[DataProvider('invalidFields')]
    public function test_returns_422_for_invalid_fields_without_writing(string $field, mixed $value): void
    {
        $room = Room::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/bookings', array_replace($this->payload($room), [$field => $value]))
            ->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertDatabaseEmpty('bookings');
    }

    public static function invalidFields(): array
    {
        return [
            'empty title' => ['title', '   '],
            'non string title' => ['title', ['meeting']],
            'long title' => ['title', str_repeat('я', 256)],
            'null byte title' => ['title', "meet\0ing"],
            'missing room' => ['room_id', 999999],
            'empty room' => ['room_id', null],
            'fractional room' => ['room_id', 1.5],
            'room SQL injection' => ['room_id', '1 OR 1=1; DROP TABLE bookings; --'],
            'empty start' => ['starts_at', null],
            'empty end' => ['ends_at', ''],
            'invalid calendar date' => ['starts_at', '2026-02-30T10:00:00+03:00'],
            'invalid leap day' => ['ends_at', '2026-02-29T11:00:00+03:00'],
            'no offset' => ['starts_at', '2026-11-02T10:00:00'],
            'end without offset' => ['ends_at', '2026-11-02T11:00:00'],
            'relative date' => ['starts_at', 'tomorrow 10:00'],
            'invalid hour' => ['starts_at', '2026-11-02T25:00:00+03:00'],
            'invalid offset hour' => ['starts_at', '2026-11-02T10:00:00+25:00'],
            'invalid offset minute' => ['starts_at', '2026-11-02T10:00:00+03:60'],
            'named timezone' => ['starts_at', '2026-11-02T10:00:00 Europe/Moscow'],
            'start SQL injection' => ['starts_at', "2026-11-02T10:00:00+03:00' OR '1'='1"],
            'end SQL injection' => ['ends_at', "2026-11-02T11:00:00+03:00'; DROP TABLE bookings; --"],
            'null byte date' => ['starts_at', "2026-11-02T10\0:00:00+03:00"],
        ];
    }

    public function test_returns_422_with_all_required_fields_for_empty_payload(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/bookings', [])->assertUnprocessable()
            ->assertJsonValidationErrors(['room_id', 'title', 'starts_at', 'ends_at']);

        $this->assertDatabaseEmpty('bookings');
    }

    public function test_returns_401_without_token(): void
    {
        $this->postJson('/api/bookings', [])->assertUnauthorized();

        $this->assertDatabaseEmpty('bookings');
    }

    /** @return array{room_id: int, title: string, starts_at: string, ends_at: string} */
    private function payload(Room $room): array
    {
        return [
            'room_id' => $room->id,
            'title' => 'Планёрка',
            'starts_at' => '2026-11-02T10:00:00+03:00',
            'ends_at' => '2026-11-02T11:00:00+03:00',
        ];
    }
}
