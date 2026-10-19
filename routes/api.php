<?php

use App\Http\Controllers\Api\WhatsAppWebhookController;
use App\Http\Controllers\Api\Workstation\AuthController;
use App\Http\Controllers\Api\Workstation\WorkstationAppointmentController;
use App\Http\Controllers\Api\Workstation\WorkstationPatientController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| WhatsApp Cloud API Webhook Routes
|--------------------------------------------------------------------------
*/
Route::get('/v1/whatsapp/webhook', [WhatsAppWebhookController::class, 'verify']);
Route::post('/v1/whatsapp/webhook', [WhatsAppWebhookController::class, 'handle']);

/*
|--------------------------------------------------------------------------
| Workstation API Routes (For Microsoft Access & Excel Secretary Desk)
|--------------------------------------------------------------------------
*/

// Public Authentication Route
Route::prefix('v1/workstation')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);

    // Authenticated Routes (Protected by Sanctum Token)
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/user', function (Request $request) {
            return response()->json(['success' => true, 'data' => $request->user()]);
        });

        // Appointments management & Daily Schedule
        Route::get('/appointments', [WorkstationAppointmentController::class, 'index']);
        Route::patch('/appointments/{id}/status', [WorkstationAppointmentController::class, 'updateStatus']);
        Route::post('/appointments/{id}/payments', [WorkstationAppointmentController::class, 'recordPayment']);

        // Patients lookup & management
        Route::get('/patients/search', [WorkstationPatientController::class, 'search']);
        Route::get('/patients/{id}', [WorkstationPatientController::class, 'show']);
        Route::patch('/patients/{id}/status', [WorkstationPatientController::class, 'updateStatus']);
    });
});
