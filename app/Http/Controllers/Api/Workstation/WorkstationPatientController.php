<?php

namespace App\Http\Controllers\Api\Workstation;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkstationPatientController extends Controller
{
    /**
     * Search patients by phone number or name (Fast search for Access & Excel).
     */
    public function search(Request $request): JsonResponse
    {
        $query = $request->query('q');

        if (!$query || strlen(trim($query)) < 2) {
            return response()->json([
                'success' => false,
                'message' => 'يرجى إدخال حرفين على الأقل للبحث',
                'data' => [],
            ], 422);
        }

        $patients = Patient::where('primary_phone', 'like', "%{$query}%")
            ->orWhere('full_name', 'like', "%{$query}%")
            ->orWhere('id_number', 'like', "%{$query}%")
            ->withCount(['appointments as total_appointments', 'appointments as no_show_count' => function ($q) {
                $q->where('status', 'no_show');
            }])
            ->limit(25)
            ->get();

        return response()->json([
            'success' => true,
            'count' => $patients->count(),
            'data' => $patients,
        ]);
    }

    /**
     * Get single patient full appointment history.
     */
    public function show(int $id): JsonResponse
    {
        $patient = Patient::with(['appointments' => function ($q) {
            $q->with('doctor.specialty')->orderByDesc('appointment_date')->orderByDesc('start_time');
        }])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $patient,
        ]);
    }

    /**
     * Update block status or patient notes from Access workstation.
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'is_blocked' => 'required|boolean',
            'blocked_reason' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        $patient = Patient::findOrFail($id);
        $patient->update([
            'is_blocked' => $request->boolean('is_blocked'),
            'blocked_reason' => $request->boolean('is_blocked') ? $request->blocked_reason : null,
            'notes' => $request->has('notes') ? $request->notes : $patient->notes,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث بيانات المريض بنجاح',
            'data' => $patient,
        ]);
    }
}
