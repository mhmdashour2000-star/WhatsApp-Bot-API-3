<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\ClinicSetting;
use App\Models\Doctor;
use App\Models\DoctorLeave;
use App\Models\DoctorSchedule;
use App\Models\Patient;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class AppointmentBookingService
{
    /**
     * Get available dates for a doctor within the next N days.
     */
    public function getAvailableDates(Doctor $doctor, int $daysAhead = 14): Collection
    {
        $dates = collect();
        $startDate = Carbon::today();

        for ($i = 0; $i < $daysAhead; $i++) {
            $currentDate = $startDate->copy()->addDays($i);
            $dayOfWeek = $currentDate->dayOfWeek; // 0=Sunday ... 6=Saturday

            // Check if doctor has a working schedule for this day
            $schedule = DoctorSchedule::where('doctor_id', $doctor->id)
                ->where('day_of_week', $dayOfWeek)
                ->where('is_working_day', true)
                ->first();

            if (!$schedule) {
                continue;
            }

            // Check full day leave
            $hasFullDayLeave = DoctorLeave::where('doctor_id', $doctor->id)
                ->where('leave_date', $currentDate->toDateString())
                ->whereNull('start_time')
                ->exists();

            if ($hasFullDayLeave) {
                continue;
            }

            // Verify if there is at least one open slot
            $slots = $this->getAvailableSlots($doctor, $currentDate->toDateString());
            if ($slots->isNotEmpty()) {
                $dates->push([
                    'date' => $currentDate->toDateString(),
                    'day_name' => $currentDate->locale('ar')->isoFormat('dddd'),
                    'formatted' => $currentDate->format('Y-m-d')
                ]);
            }
        }

        return $dates;
    }

    /**
     * Generate and calculate available time slots for a given doctor and date.
     */
    public function getAvailableSlots(Doctor $doctor, string $date): Collection
    {
        $carbonDate = Carbon::parse($date);
        $dayOfWeek = $carbonDate->dayOfWeek;

        $schedule = DoctorSchedule::where('doctor_id', $doctor->id)
            ->where('day_of_week', $dayOfWeek)
            ->where('is_working_day', true)
            ->first();

        if (!$schedule) {
            return collect();
        }

        $slotMinutes = $doctor->slot_duration_minutes ?? 30;
        $workStart = Carbon::parse($date . ' ' . $schedule->start_time);
        $workEnd = Carbon::parse($date . ' ' . $schedule->end_time);

        $breakStart = $schedule->break_start ? Carbon::parse($date . ' ' . $schedule->break_start) : null;
        $breakEnd = $schedule->break_end ? Carbon::parse($date . ' ' . $schedule->break_end) : null;

        // Fetch leaves on this date
        $partialLeaves = DoctorLeave::where('doctor_id', $doctor->id)
            ->where('leave_date', $date)
            ->get();

        // Expire outdated holding appointments first
        $this->cleanupExpiredHolds();

        // Fetch booked and active holding appointments
        $bookedSlots = Appointment::where('doctor_id', $doctor->id)
            ->where('appointment_date', $date)
            ->whereIn('status', ['confirmed', 'completed', 'holding'])
            ->get();

        $now = Carbon::now();
        $availableSlots = collect();
        $currentSlot = $workStart->copy();

        while ($currentSlot->copy()->addMinutes($slotMinutes)->lte($workEnd)) {
            $slotEnd = $currentSlot->copy()->addMinutes($slotMinutes);

            // Skip past slots if booking for today
            if ($carbonDate->isToday() && $currentSlot->lte($now)) {
                $currentSlot->addMinutes($slotMinutes);
                continue;
            }

            // Check overlap with break time
            if ($breakStart && $breakEnd) {
                if ($currentSlot->lt($breakEnd) && $slotEnd->gt($breakStart)) {
                    $currentSlot->addMinutes($slotMinutes);
                    continue;
                }
            }

            // Check overlap with partial leaves
            $isUnderLeave = false;
            foreach ($partialLeaves as $leave) {
                if ($leave->start_time && $leave->end_time) {
                    $lStart = Carbon::parse($date . ' ' . $leave->start_time);
                    $lEnd = Carbon::parse($date . ' ' . $leave->end_time);
                    if ($currentSlot->lt($lEnd) && $slotEnd->gt($lStart)) {
                        $isUnderLeave = true;
                        break;
                    }
                }
            }
            if ($isUnderLeave) {
                $currentSlot->addMinutes($slotMinutes);
                continue;
            }

            // Check overlap with existing appointments
            $isBooked = $bookedSlots->contains(function ($appt) use ($currentSlot, $slotEnd) {
                $apptStart = Carbon::parse($appt->appointment_date->format('Y-m-d') . ' ' . $appt->start_time);
                $apptEnd = Carbon::parse($appt->appointment_date->format('Y-m-d') . ' ' . $appt->end_time);
                return $currentSlot->lt($apptEnd) && $slotEnd->gt($apptStart);
            });

            if (!$isBooked) {
                $availableSlots->push([
                    'start_time' => $currentSlot->format('H:i:s'),
                    'end_time' => $slotEnd->format('H:i:s'),
                    'display' => $currentSlot->format('H:i') . ' - ' . $slotEnd->format('H:i'),
                ]);
            }

            $currentSlot->addMinutes($slotMinutes);
        }

        return $availableSlots;
    }

    /**
     * Atomically hold a slot for a patient (Race condition safe).
     */
    public function holdSlot(
        Patient $patient,
        Doctor $doctor,
        string $date,
        string $startTime,
        string $endTime,
        string $source = 'whatsapp'
    ): Appointment {
        if ($patient->is_blocked) {
            throw new RuntimeException('المريض محظور من الحجز: ' . ($patient->blocked_reason ?? 'تكرار الغياب'));
        }

        $holdMinutes = (int) ClinicSetting::get('slot_holding_minutes', 10);
        $expiresAt = Carbon::now()->addMinutes($holdMinutes);
        $normStart = Carbon::parse($startTime)->format('H:i:s');
        $normEnd = Carbon::parse($endTime)->format('H:i:s');

        return DB::transaction(function () use ($patient, $doctor, $date, $normStart, $normEnd, $source, $expiresAt) {
            $this->cleanupExpiredHolds();

            // Lock conflicting records for update
            $conflict = Appointment::where('doctor_id', $doctor->id)
                ->whereDate('appointment_date', $date)
                ->whereTime('start_time', $normStart)
                ->whereIn('status', ['confirmed', 'completed', 'holding'])
                ->lockForUpdate()
                ->first();

            if ($conflict) {
                throw new RuntimeException('عذراً، هذا الموعد تم اختياره للتو من قبل شخص آخر. يرجى اختيار موعد بديل.');
            }

            return Appointment::create([
                'reference_code' => 'AP-' . strtoupper(Str::random(8)),
                'patient_id' => $patient->id,
                'doctor_id' => $doctor->id,
                'appointment_date' => $date,
                'start_time' => $normStart,
                'end_time' => $normEnd,
                'status' => 'holding',
                'holding_expires_at' => $expiresAt,
                'version' => 1,
                'source' => $source,
            ]);
        });
    }

    /**
     * Confirm a holding appointment.
     */
    public function confirmAppointment(Appointment $appointment): Appointment
    {
        return DB::transaction(function () use ($appointment) {
            $locked = Appointment::where('id', $appointment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== 'holding') {
                throw new RuntimeException('الموعد غير صالح للتأكيد (حالته الحالية: ' . $locked->status . ')');
            }

            if ($locked->holding_expires_at && Carbon::now()->gt($locked->holding_expires_at)) {
                $locked->update(['status' => 'cancelled_by_clinic', 'cancellation_reason' => 'انتهت مهلة التأكيد المؤقتة']);
                throw new RuntimeException('انتهت المهلة الزمنية المتاحة لتأكيد الحجز. يرجى إعادة اختيار الموعد.');
            }

            $locked->update([
                'status' => 'confirmed',
                'holding_expires_at' => null,
                'version' => $locked->version + 1,
            ]);

            return $locked;
        });
    }

    /**
     * Cancel an appointment respecting clinic rule window.
     */
    public function cancelAppointment(Appointment $appointment, string $reason, string $cancelledBy = 'patient'): Appointment
    {
        $minHours = (int) ClinicSetting::get('cancellation_min_hours_before', 4);
        $appointmentDateTime = Carbon::parse($appointment->appointment_date->format('Y-m-d') . ' ' . $appointment->start_time);

        if ($cancelledBy === 'patient' && Carbon::now()->addHours($minHours)->gt($appointmentDateTime)) {
            throw new RuntimeException("لا يمكن إلغاء الموعد قبل أقل من {$minHours} ساعات من موعده عبر النظام الآلي. يرجى التواصل هاتفياً مع إدارة العيادة.");
        }

        $appointment->update([
            'status' => $cancelledBy === 'patient' ? 'cancelled_by_patient' : 'cancelled_by_clinic',
            'cancellation_reason' => $reason,
            'cancelled_at' => Carbon::now(),
            'version' => $appointment->version + 1,
        ]);

        return $appointment;
    }

    /**
     * Clean up expired holds.
     */
    public function cleanupExpiredHolds(): int
    {
        return Appointment::where('status', 'holding')
            ->whereNotNull('holding_expires_at')
            ->where('holding_expires_at', '<', Carbon::now())
            ->update([
                'status' => 'cancelled_by_clinic',
                'cancellation_reason' => 'انتهاء مهلة التأكيد',
                'cancelled_at' => Carbon::now(),
            ]);
    }
}
