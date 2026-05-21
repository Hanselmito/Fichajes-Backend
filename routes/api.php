<?php

use Illuminate\Support\Facades\Route;

$todo = static fn (string $endpoint) => static function () use ($endpoint) {
    return response()->json([
        'success' => false,
        'message' => "Endpoint [$endpoint] pendiente de implementar en Laravel.",
    ], 501);
};

Route::get('/health', static function () {
    return response()->json([
        'success' => true,
        'message' => 'API Laravel lista',
    ]);
});

Route::prefix('auth')->group(function () use ($todo) {
    Route::post('/login', $todo('auth.login'));
    Route::post('/logout', $todo('auth.logout'));
    Route::get('/me', $todo('auth.me'));
});

Route::get('/dashboard', $todo('dashboard.index'));
Route::get('/reports', $todo('reports.index'));
Route::get('/work-hours', $todo('work-hours.index'));

Route::apiResource('users', \App\Http\Controllers\Api\UserController::class);
Route::apiResource('zones', \App\Http\Controllers\Api\ZoneController::class);
Route::get('/zones-minimal', $todo('zones-minimal.index'));
Route::apiResource('clients', \App\Http\Controllers\Api\ClientController::class);
Route::apiResource('records', \App\Http\Controllers\Api\RecordController::class);
Route::apiResource('incidencias', \App\Http\Controllers\Api\IncidenciaController::class);
Route::apiResource('vacations', \App\Http\Controllers\Api\VacationController::class);

Route::get('/vacation-requests', $todo('vacation-requests.index'));
Route::post('/vacation-requests', $todo('vacation-requests.store'));
Route::put('/vacation-requests/{vacationRequest}', $todo('vacation-requests.update'));
Route::delete('/vacation-requests/{vacationRequest}', $todo('vacation-requests.destroy'));

Route::get('/notifications', \App\Http\Controllers\Api\NotificationController::class . '@index');
Route::delete('/notifications/{notification}', \App\Http\Controllers\Api\NotificationController::class . '@destroy');
Route::put('/notifications/{notification}/read', $todo('notifications.read'));
Route::put('/notifications/read-all', $todo('notifications.read-all'));
Route::get('/notifications/settings', $todo('notifications.settings.show'));
Route::put('/notifications/settings', $todo('notifications.settings.update'));

Route::get('/modifications', $todo('modifications.index'));
Route::post('/modifications', $todo('modifications.store'));
Route::put('/modifications/{modification}', $todo('modifications.update'));

Route::get('/breaks', $todo('breaks.index'));
Route::post('/breaks', $todo('breaks.store'));
Route::put('/breaks/{break}', $todo('breaks.update'));

Route::get('/calendars', $todo('calendars.index'));
Route::post('/calendars', $todo('calendars.store'));
Route::put('/calendars/{calendar}', $todo('calendars.update'));
Route::delete('/calendars/{calendar}', $todo('calendars.destroy'));

Route::get('/employee-schedules', $todo('employee-schedules.index'));
Route::post('/employee-schedules', $todo('employee-schedules.store'));
Route::put('/employee-schedules/{employeeSchedule}', $todo('employee-schedules.update'));

Route::get('/schedule-history', $todo('schedule-history.index'));

Route::get('/schedules', \App\Http\Controllers\Api\ScheduleController::class . '@index');
Route::post('/schedules', \App\Http\Controllers\Api\ScheduleController::class . '@store');

Route::get('/quadrants', $todo('quadrants.index'));
Route::post('/quadrants', $todo('quadrants.store'));
Route::put('/quadrants/{quadrant}', $todo('quadrants.update'));
Route::delete('/quadrants/{quadrant}', $todo('quadrants.destroy'));

Route::get('/services', $todo('services.index'));
Route::post('/services', $todo('services.store'));
Route::put('/services/{service}', $todo('services.update'));
Route::delete('/services/{service}', $todo('services.destroy'));

Route::get('/zone-holidays', $todo('zone-holidays.index'));
Route::post('/zone-holidays', $todo('zone-holidays.store'));
Route::delete('/zone-holidays/{zoneHoliday}', $todo('zone-holidays.destroy'));

Route::get('/tolerance', $todo('tolerance.show'));
Route::put('/tolerance', $todo('tolerance.update'));

Route::get('/bolsa-anotaciones', $todo('bolsa-anotaciones.index'));
Route::post('/bolsa-anotaciones', $todo('bolsa-anotaciones.store'));
Route::put('/bolsa-anotaciones/{anotacion}', $todo('bolsa-anotaciones.update'));
Route::delete('/bolsa-anotaciones/{anotacion}', $todo('bolsa-anotaciones.destroy'));

Route::post('/qr-generator', $todo('qr-generator.store'));
