<?php

use App\Http\Controllers\BookingController;
use App\Models\Room;
use Illuminate\Support\Facades\Route;

Route::bind('room', function (string $id): Room {
    abort_unless(filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false, 404);

    return Room::findOrFail($id);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/bookings', [BookingController::class, 'index']);
    Route::post('/bookings', [BookingController::class, 'store']);
    Route::delete('/bookings/{id}', [BookingController::class, 'destroy']);
    Route::get('/rooms/{room}/bookings/today', [BookingController::class, 'today']);
});
