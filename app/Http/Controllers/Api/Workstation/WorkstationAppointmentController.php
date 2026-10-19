<?php

namespace App\Http\Controllers\Api\Workstation;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Payment;
use App\Models\SyncAuditLog;
use App\Services\AppointmentBookingService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WorkstationAppointmentController extends Controller
{
    public function __construct(
        protected AppointmentBookingService $bookingService
    ) {}

    /**
     * Get appointments for a specific date (defaults to today) with filters.
     * Ideal for Excel daily schedule pulls and Access forms.
     */
    public function index(Request $request): JsonResponse
    {
        $date = $request->query('date', Carbon::today()->toDateString());
        $doctorId = $request->query('doctor_id');
        $status = $request->query('status');

        $query = Appointment::with(['patient', 'doctor.specialty', 'payments'])
            ->whereDate('appointment_date', $date);

        if ($doctorId) {
            $query->where('doctor_id', $doctorId);
        }

        if ($status) {
            $query->where('status', $status);
        }

        $appointments = $query->orderBy('start_time')->get();

        return response()->json([
            'success' => true,
            'date' => $date,
            'count' => $appointments->count(),
            'data' => $appointments->map(function ($appt) {
                return [
                    'id' => $appt->id,
                    'reference_code' => $appt->reference_code,
                    'patient_id' => $appt->patient_id,
                    'patient_name' => $appt->patient->full_name ?? '',
                    'patient_phone' => $appt->patient->primary_phone ?? '',
                    'doctor_id' => $appt->doctor_id,
                    'doctor_name' => $appt->doctor->full_name ?? '',
                    'specialty_name' => $appt->doctor->specialty->name ?? '',
                    'appointment_date' => $appt->appointment_date->format('Y-m-d'),
                    'start_time' => $appt->start_time,
                    'end_time' => $appt->end_time,
                    'status' => $appt->status,
                    'attended' => (bool) $appt->attended,
                    'source' => $appt->source,
                    'version' => $appt->version,
                    'total_paid' => $appt->payments->sum('amount'),
                    'created_at' => $appt->created_at?->toIso8601String(),
                ];
            }),
        ]);
    }

    /**
     * Update appointment attendance status or general status with Optimistic Concurrency Control.
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'status' => 'required|in:confirmed,completed,cancelled_by_clinic,no_show',
            'attended' => 'nullable|boolean',
            'cancellation_reason' => 'nullable|string|max:255',
            'client_version' => 'required|integer', // Required for optimistic concurrency lock
        ]);

        return DB::transaction(function () use ($request, $id) {
            $appointment = Appointment::where('id', $id)->lockForUpdate()->first();

            if (!$appointment) {
                return response()->json([
                    'success' => false,
                    'message' => 'الموعد غير موجود',
                ], 404);
            }

            // Conflict check: optimistic locking
            if ($appointment->version !== (int) $request->client_version) {
                // Log conflict
                SyncAuditLog::create([
                    'entity_name' => 'appointments',
                    'entity_id' => $appointment->id,
                    'action_type' => 'sync_conflict',
                    'changed_by' => $request->user()->name ?? 'workstation',
                    'client_version' => $request->client_version,
                    'server_version' => $appointment->version,
                    'ip_address' => $request->ip(),
                ]);

                return response()->json([
                    'success' => false,
                    'conflict' => true,
                    'message' => 'تعارض في التعديل: تم تعديل هذا الموعد مسبقاً من مصدر آخر. يرجى تحديث البيانات والمحاولة مجدداً.',
                    'current_version' => $appointment->version,
                    'current_status' => $appointment->status,
                ], 409);
            }

            $oldValues = $appointment->toArray();

            $appointment->status = $request->status;
            if ($request->has('attended')) {
                $appointment->attended = $request->boolean('attended');
            }
            if ($request->status === 'cancelled_by_clinic') {
                $appointment->cancellation_reason = $request->cancellation_reason ?? 'إلغاء من قبل إدارة العيادة';
                $appointment->cancelled_at = Carbon::now();
            }

            $appointment->version = $appointment->version + 1;
            $appointment->save();

            // Log update
            SyncAuditLog::create([
                'entity_name' => 'appointments',
                'entity_id' => $appointment->id,
                'action_type' => 'update',
                'changed_by' => $request->user()->name ?? 'workstation',
                'old_values' => $oldValues,
                'new_values' => $appointment->fresh()->toArray(),
                'client_version' => $request->client_version,
                'server_version' => $appointment->version,
                'ip_address' => $request->ip(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'تم تحديث حالة الموعد بنجاح',
                'new_version' => $appointment->version,
                'appointment' => $appointment,
            ]);
        });
    }

    /**
     * Record a payment for an appointment from Access or Excel.
     */
    public function recordPayment(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'nullable|string|max:10',
            'payment_method' => 'required|in:cash,credit_card,bank_transfer,insurance',
            'receipt_reference' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:255',
        ]);

        $appointment = Appointment::findOrFail($id);

        $payment = Payment::create([
            'appointment_id' => $appointment->id,
            'amount' => $request->amount,
            'currency' => $request->currency ?? 'TRY',
            'payment_method' => $request->payment_method,
            'receipt_reference' => $request->receipt_reference,
            'notes' => $request->notes,
            'received_by' => $request->user()->name ?? 'Secretary',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تم تسجيل الدفعة المالية بنجاح',
            'data' => $payment,
        ]);
    }
}
