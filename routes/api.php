<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BolsaAnotacionController;
use App\Http\Controllers\Api\CalendarController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EmployeeScheduleController;
use App\Http\Controllers\Api\BreakController;
use App\Http\Controllers\Api\ModificationController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\QrGeneratorController;
use App\Http\Controllers\Api\ReportsController;
use App\Http\Controllers\Api\ScheduleHistoryController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\ToleranceController;
use App\Http\Controllers\Api\VacationRequestController;
use App\Http\Controllers\Api\WorkHoursController;
use App\Http\Controllers\Api\ZoneHolidayController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

$todo = static fn (string $endpoint) => static function () use ($endpoint) {
    return response()->json([
        'success' => false,
        'message' => "Endpoint [$endpoint] pendiente de implementar en Laravel.",
    ], 501);
};

Route::get('/health', static function () {
    try {
        DB::connection()->getPdo();

        return response()->json([
            'success' => true,
            'message' => 'API Laravel lista',
            'checks' => [
                'database' => [
                    'status' => 'up',
                    'driver' => DB::connection()->getDriverName(),
                ],
            ],
        ]);
    } catch (\Throwable $exception) {
        Log::warning('Healthcheck database failure', [
            'error' => $exception->getMessage(),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Dependencias no disponibles',
            'checks' => [
                'database' => [
                    'status' => 'down',
                ],
            ],
        ], 503);
    }
});

Route::prefix('auth')->group(function () use ($todo) {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
    Route::post('/refresh', [AuthController::class, 'refresh']);

    Route::middleware('legacy-api.auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::get('/capabilities', [AuthController::class, 'capabilities']);
    });
});

Route::middleware('legacy-api.auth')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/reports', [ReportsController::class, 'index']);
    Route::get('/reports/{mode}', [ReportsController::class, 'index']);
    Route::get('/work-hours', [WorkHoursController::class, 'index']);

    Route::apiResource('users', \App\Http\Controllers\Api\UserController::class);
    Route::post('/zones/{zone}/regenerate-qr', [\App\Http\Controllers\Api\ZoneController::class, 'regenerateQr']);
    Route::apiResource('zones', \App\Http\Controllers\Api\ZoneController::class);
    Route::get('/zones-minimal', [\App\Http\Controllers\Api\ZoneController::class, 'minimal']);
    Route::post('/clients/geocode', [\App\Http\Controllers\Api\ClientController::class, 'geocode']);
    Route::post('/clients/geocode-all', [\App\Http\Controllers\Api\ClientController::class, 'geocodeAll']);
    Route::get('/clients/qr/{qrCode}', [\App\Http\Controllers\Api\ClientController::class, 'lookupByQr']);
    Route::post('/clients/{client}/regenerate-qr', [\App\Http\Controllers\Api\ClientController::class, 'regenerateQr']);
    Route::apiResource('clients', \App\Http\Controllers\Api\ClientController::class);
    Route::put('/records/{record}/confirm', [\App\Http\Controllers\Api\RecordController::class, 'confirm']);
    Route::apiResource('records', \App\Http\Controllers\Api\RecordController::class);
    Route::apiResource('incidencias', \App\Http\Controllers\Api\IncidenciaController::class);
    Route::put('/incidencias', [\App\Http\Controllers\Api\IncidenciaController::class, 'update']);
    Route::delete('/incidencias', [\App\Http\Controllers\Api\IncidenciaController::class, 'destroy']);
    Route::apiResource('vacations', \App\Http\Controllers\Api\VacationController::class);
    Route::put('/vacations/{vacation}/approve', [\App\Http\Controllers\Api\VacationController::class, 'approve']);
    Route::put('/vacations/{vacation}/reject', [\App\Http\Controllers\Api\VacationController::class, 'reject']);

    Route::get('/vacation-requests/stats', [VacationRequestController::class, 'stats']);
    Route::get('/vacation-requests', [VacationRequestController::class, 'index']);
    Route::get('/vacation-requests/{vacationRequest}', [VacationRequestController::class, 'show']);
    Route::post('/vacation-requests', [VacationRequestController::class, 'store']);
    Route::put('/vacation-requests/{vacationRequest}/approve', [VacationRequestController::class, 'approve']);
    Route::put('/vacation-requests/{vacationRequest}/reject', [VacationRequestController::class, 'reject']);
    Route::delete('/vacation-requests/{vacationRequest}', [VacationRequestController::class, 'destroy']);

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::delete('/notifications/{notification}', [NotificationController::class, 'destroy']);
    Route::put('/notifications/{notification}/read', [NotificationController::class, 'read']);
    Route::put('/notifications/read-all', [NotificationController::class, 'readAll']);
    Route::get('/notifications/settings', [NotificationController::class, 'settings']);
    Route::put('/notifications/settings', [NotificationController::class, 'updateSettings']);

    Route::get('/modifications', [ModificationController::class, 'indexRequests']);
    Route::post('/modifications', [ModificationController::class, 'storeRequest']);
    Route::get('/modifications/requests', [ModificationController::class, 'indexRequests']);
    Route::post('/modifications/requests', [ModificationController::class, 'storeRequest']);
    Route::put('/modifications/requests/{modificationRequest}/approve', [ModificationController::class, 'approveRequest']);
    Route::put('/modifications/requests/{modificationRequest}/reject', [ModificationController::class, 'rejectRequest']);
    Route::get('/modifications/confirmations', [ModificationController::class, 'listConfirmations']);
    Route::post('/modifications/confirmations', [ModificationController::class, 'storeConfirmation']);
    Route::put('/modifications/confirmations/{confirmation}/confirm', [ModificationController::class, 'confirmConfirmation']);
    Route::put('/modifications/confirmations/{confirmation}/reject', [ModificationController::class, 'rejectConfirmation']);

    Route::get('/breaks', [BreakController::class, 'index']);
    Route::post('/breaks', [BreakController::class, 'store']);
    Route::put('/breaks', [BreakController::class, 'update']);
    Route::put('/breaks/{break}', [BreakController::class, 'update']);

    Route::get('/calendars', [CalendarController::class, 'index']);
    Route::post('/calendars', [CalendarController::class, 'store']);
    Route::put('/calendars/{calendar}', [CalendarController::class, 'update']);
    Route::delete('/calendars/{calendar}', [CalendarController::class, 'destroy']);
    Route::get('/calendars/{calendar}/holidays', [CalendarController::class, 'holidays']);
    Route::post('/calendars/{calendar}/holidays', [CalendarController::class, 'storeHoliday']);
    Route::delete('/calendars/{calendar}/holidays/{holidayId}', [CalendarController::class, 'destroyHoliday']);

    Route::get('/employee-schedules', [EmployeeScheduleController::class, 'index']);
    Route::post('/employee-schedules', [EmployeeScheduleController::class, 'store']);
    Route::put('/employee-schedules/{employeeSchedule}', [EmployeeScheduleController::class, 'update']);

    Route::get('/schedule-history', [ScheduleHistoryController::class, 'index']);

    Route::get('/schedules/status/today', [\App\Http\Controllers\Api\ScheduleController::class, 'index']);
    Route::get('/schedules/{employee}', [\App\Http\Controllers\Api\ScheduleController::class, 'show']);
    Route::get('/schedules', [\App\Http\Controllers\Api\ScheduleController::class, 'index']);
    Route::post('/schedules', [\App\Http\Controllers\Api\ScheduleController::class, 'store']);

    Route::get('/quadrants/{quadrant}/assignments/{assignment}/exceptions', [\App\Http\Controllers\Api\QuadrantController::class, 'exceptions']);
    Route::post('/quadrants/{quadrant}/assignments/{assignment}/exceptions', [\App\Http\Controllers\Api\QuadrantController::class, 'storeException']);
    Route::delete('/quadrants/{quadrant}/assignments/{assignment}/exceptions/{exception}', [\App\Http\Controllers\Api\QuadrantController::class, 'destroyException']);
    Route::get('/quadrants/{quadrant}/assignments', [\App\Http\Controllers\Api\QuadrantController::class, 'assignments']);
    Route::post('/quadrants/{quadrant}/assignments', [\App\Http\Controllers\Api\QuadrantController::class, 'storeAssignment']);
    Route::put('/quadrants/{quadrant}/assignments/{assignment}', [\App\Http\Controllers\Api\QuadrantController::class, 'updateAssignment']);
    Route::delete('/quadrants/{quadrant}/assignments/{assignment}', [\App\Http\Controllers\Api\QuadrantController::class, 'destroyAssignment']);
    Route::get('/quadrants/{quadrant}', [\App\Http\Controllers\Api\QuadrantController::class, 'show']);
    Route::get('/quadrants', [\App\Http\Controllers\Api\QuadrantController::class, 'index']);
    Route::post('/quadrants', [\App\Http\Controllers\Api\QuadrantController::class, 'store']);
    Route::put('/quadrants/{quadrant}', [\App\Http\Controllers\Api\QuadrantController::class, 'update']);
    Route::delete('/quadrants/{quadrant}', [\App\Http\Controllers\Api\QuadrantController::class, 'destroy']);

    Route::get('/services', [ServiceController::class, 'index']);
    Route::post('/services', [ServiceController::class, 'store']);
    Route::put('/services/{service}', [ServiceController::class, 'update']);
    Route::delete('/services/{service}', [ServiceController::class, 'destroy']);

    Route::get('/zone-holidays', [ZoneHolidayController::class, 'index']);
    Route::post('/zone-holidays', [ZoneHolidayController::class, 'store']);
    Route::delete('/zone-holidays', [ZoneHolidayController::class, 'destroy']);
    Route::delete('/zone-holidays/{zoneHoliday}', [ZoneHolidayController::class, 'destroy']);

    Route::get('/tolerance', [ToleranceController::class, 'showZone']);
    Route::put('/tolerance', [ToleranceController::class, 'updateZone']);
    Route::get('/tolerance/zone', [ToleranceController::class, 'showZone']);
    Route::put('/tolerance/zone', [ToleranceController::class, 'updateZone']);
    Route::get('/tolerance/employee/{employeeId}', [ToleranceController::class, 'showEmployee']);
    Route::put('/tolerance/employee/{employeeId}', [ToleranceController::class, 'updateEmployee']);
    Route::put('/tolerance/employee/{employeeId}/all', [ToleranceController::class, 'updateEmployeeAll']);
    Route::get('/tolerance/presets', [ToleranceController::class, 'presets']);

    Route::get('/bolsa-anotaciones', [BolsaAnotacionController::class, 'index']);
    Route::post('/bolsa-anotaciones', [BolsaAnotacionController::class, 'store']);
    Route::put('/bolsa-anotaciones/{anotacion}', [BolsaAnotacionController::class, 'update']);
    Route::delete('/bolsa-anotaciones/{anotacion}', [BolsaAnotacionController::class, 'destroy']);

    Route::get('/qr-generator', [QrGeneratorController::class, 'show']);
    Route::post('/qr-generator', [QrGeneratorController::class, 'show']);
});
