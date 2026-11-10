<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Room;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class BookingController extends Controller
{
    public function index(Request $request)
    {
        return Booking::where('user_id', $request->user()->id)
            ->orderByRaw('starts_at DESC')
            ->paginate(20);
    }

    public function store(Request $request): Booking
    {
        $dateRules = [
            'bail',
            'required',
            'string',
            'regex:/\A\d{4}-\d{2}-\d{2}T(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d(?:\.\d{1,6})?)?(?:Z|[+-](?:[01]\d|2[0-3])(?::?[0-5]\d)?)\z/',
            'date',
        ];
        $data = $request->validate([
            'room_id' => ['bail', 'required', 'integer', 'min:1', 'exists:rooms,id'],
            'title' => ['required', 'string', 'max:255', 'not_regex:/\x00/'],
            'starts_at' => $dateRules,
            'ends_at' => $dateRules,
        ], [
            'starts_at.regex' => 'Укажите дату ISO 8601 с UTC-смещением или Z.',
            'ends_at.regex' => 'Укажите дату ISO 8601 с UTC-смещением или Z.',
            'title.not_regex' => 'Название не должно содержать нулевой байт.',
        ]);

        $room = Room::with('office')->findOrFail($data['room_id']);
        $start = CarbonImmutable::parse($data['starts_at'])->setTimezone($room->office->timezone);
        $end = CarbonImmutable::parse($data['ends_at'])->setTimezone($room->office->timezone);
        $durationSeconds = $end->getTimestamp() - $start->getTimestamp();

        if ($durationSeconds < 900 || $durationSeconds > 14400 || $durationSeconds % 900 !== 0) {
            throw ValidationException::withMessages([
                'ends_at' => 'Длительность должна быть от 15 до 240 минут с шагом 15 минут.',
            ]);
        }

        foreach (['starts_at' => $start, 'ends_at' => $end] as $field => $time) {
            if ($time->minute % 15 !== 0 || $time->second !== 0 || $time->micro !== 0) {
                throw ValidationException::withMessages([
                    $field => 'Время должно быть на сетке 15 минут по часовому поясу офиса, без секунд и долей секунды.',
                ]);
            }
        }

        if ($start->toDateString() !== $end->toDateString() || $start->hour < 9 || $end->format('H:i') > '20:00') {
            throw ValidationException::withMessages([
                'starts_at' => 'Бронь должна целиком укладываться в 09:00–20:00 одного дня офиса.',
            ]);
        }

        $startUtc = $start->utc();
        $endUtc = $end->utc();

        if (Booking::where('room_id', $room->id)
            ->where('starts_at', '<', $endUtc)
            ->where('ends_at', '>', $startUtc)
            ->exists()) {
            throw ValidationException::withMessages([
                'starts_at' => 'Комната занята в выбранное время.',
            ]);
        }

        $booking = Booking::create([
            'room_id' => $room->id,
            'user_id' => $request->user()->id,
            'title' => $data['title'],
            'starts_at' => $startUtc,
            'ends_at' => $endUtc,
        ]);

        Log::withContext(['booking_id' => $booking->id]);
        Log::channel('bookings')->info('Booking created');

        return $booking;
    }

    public function destroy(Request $request, string $id): Response
    {
        abort_unless(filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false, 404);

        $request->user()->bookings()->findOrFail($id)->delete();

        return response()->noContent();
    }

    public function today(Room $room)
    {
        return $room->bookings()
            ->whereDate('starts_at', today())
            ->get()
            ->map(fn ($b) => [
                'time' => $b->starts_at . ' – ' . $b->ends_at,
                'author' => $b->user->name,
            ]);
    }
}
