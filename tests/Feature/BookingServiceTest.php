<?php

namespace Tests\Feature;

use App\Models\Doctor;
use App\Models\Patient;
use App\Services\AppointmentBookingService;
use Carbon\Carbon;
use Database\Seeders\ClinicSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ClinicSeeder::class);
    }

    public function test_can_calculate_available_slots(): void
    {
        $service = app(AppointmentBookingService::class);
        $doctor = Doctor::first();

        // Find next Monday
        $nextMonday = Carbon::now()->next(Carbon::MONDAY)->format('Y-m-d');
        $slots = $service->getAvailableSlots($doctor, $nextMonday);

        $this->assertNotEmpty($slots);
        // Verify break time (13:00 - 14:00) is excluded
        foreach ($slots as $slot) {
            $this->assertFalse(
                $slot['start_time'] >= '13:00:00' && $slot['start_time'] < '14:00:00',
                'Slot inside break time was found: ' . $slot['start_time']
            );
        }
    }

    public function test_can_hold_and_confirm_slot_and_prevent_double_booking(): void
    {
        $service = app(AppointmentBookingService::class);
        $doctor = Doctor::first();
        $patient1 = Patient::create([
            'primary_phone' => '905551112233',
            'full_name' => 'محمد أحمد',
        ]);
        $patient2 = Patient::create([
            'primary_phone' => '905559998877',
            'full_name' => 'خالد علي',
        ]);

        $nextMonday = Carbon::now()->next(Carbon::MONDAY)->format('Y-m-d');
        $slots = $service->getAvailableSlots($doctor, $nextMonday);
        $firstSlot = $slots->first();

        // 1. Patient 1 holds slot
        $appt = $service->holdSlot($patient1, $doctor, $nextMonday, $firstSlot['start_time'], $firstSlot['end_time']);
        $this->assertEquals('holding', $appt->status);

        // 2. Patient 2 attempts same slot -> Expect Exception
        $this->expectException(\RuntimeException::class);
        $service->holdSlot($patient2, $doctor, $nextMonday, $firstSlot['start_time'], $firstSlot['end_time']);
    }
}
