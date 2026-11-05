<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\ClinicSetting;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\Patient;
use App\Models\Specialty;
use App\Models\WhatsappSession;
use App\Services\AppointmentBookingService;
use App\Services\WhatsAppCloudApiService;
use App\Services\WhatsAppFlowService;
use Carbon\Carbon;
use Database\Seeders\ClinicSeeder;
use Mockery;
use Tests\TestCase;

class WhatsAppNavigationTest extends TestCase
{
    protected $fakeApi;
    public array $sentMessages = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh');
        $this->seed(ClinicSeeder::class);

        $this->fakeApi = Mockery::mock(WhatsAppCloudApiService::class);
        $test = $this;
        $this->fakeApi->shouldReceive('cleanPhoneNumber')->andReturnUsing(function ($phone) {
            return preg_replace('/\D+/', '', $phone);
        });
        $this->fakeApi->shouldReceive('sendTextMessage')->andReturnUsing(function ($to, $msg) use ($test) {
            $test->sentMessages[] = ['type' => 'text', 'to' => $to, 'msg' => $msg];
            return true;
        });
        $this->fakeApi->shouldReceive('sendButtonMessage')->andReturnUsing(function ($to, $body, $buttons, $header = null, $footer = null) use ($test) {
            $test->sentMessages[] = ['type' => 'button', 'to' => $to, 'body' => $body, 'buttons' => $buttons, 'header' => $header, 'footer' => $footer];
            return true;
        });
        $this->fakeApi->shouldReceive('sendListMessage')->andReturnUsing(function ($to, $body, $buttonText, $sections, $header = null, $footer = null) use ($test) {
            $test->sentMessages[] = ['type' => 'list', 'to' => $to, 'body' => $body, 'buttonText' => $buttonText, 'sections' => $sections, 'header' => $header, 'footer' => $footer];
            return true;
        });
        $this->sentMessages = [];
    }

    protected function makeFlowService(int $slotCount = 10, ?AppointmentBookingService $bookingService = null): WhatsAppFlowService
    {
        if ($bookingService) {
            return new WhatsAppFlowService($this->fakeApi, $bookingService);
        }

        $bookingMock = Mockery::mock(AppointmentBookingService::class);
        $bookingMock->shouldReceive('getAvailableDates')->andReturn(collect([
            ['date' => '2026-11-08', 'day_name' => 'Sunday', 'formatted' => '2026-11-08'],
        ]));

        $slots = [];
        for ($i = 0; $i < $slotCount; $i++) {
            $formattedIndex = str_pad($i, 2, '0', STR_PAD_LEFT);
            $nextIndex = str_pad($i + 1, 2, '0', STR_PAD_LEFT);
            $slots[] = [
                'display' => "08:{$formattedIndex} - 08:{$nextIndex}",
                'start_time' => "08:{$formattedIndex}:00",
                'end_time' => "08:{$nextIndex}:00",
            ];
        }

        $bookingMock->shouldReceive('getAvailableSlots')->andReturn(collect($slots));
        $bookingMock->shouldReceive('holdSlot')->andReturnUsing(function ($patient, $doctor, $date, $start, $end, $src) {
            return Appointment::create([
                'reference_code' => 'AP-TEST-' . rand(1000, 9999),
                'patient_id' => $patient->id,
                'doctor_id' => $doctor->id,
                'appointment_date' => $date,
                'start_time' => $start ?? '08:00:00',
                'end_time' => $end ?? '08:30:00',
                'status' => 'holding',
                'holding_expires_at' => Carbon::now()->addMinutes(10),
                'source' => $src ?? 'whatsapp',
            ]);
        });
        $bookingMock->shouldReceive('confirmAppointment')->andReturnUsing(function ($appt) {
            $appt->update([
                'status' => 'confirmed',
                'holding_expires_at' => null,
            ]);
            return $appt;
        });

        return new WhatsAppFlowService($this->fakeApi, $bookingMock);
    }

    /** 1. Main menu displays the three required buttons */
    public function testMainMenuDisplaysTheThreeRequiredButtons(): void
    {
        $flow = $this->makeFlowService();
        $flow->handle('905551112233', 'menu');

        $this->assertNotEmpty($this->sentMessages);
        $last = end($this->sentMessages);

        $this->assertEquals('button', $last['type']);
        $this->assertStringContainsString("Welcome to Al-Shifa Medical Clinic.", $last['body']);
        $this->assertStringContainsString("This bot helps you manage your clinic appointments. Please select one of the options below.", $last['body']);

        $buttons = $last['buttons'];
        $this->assertCount(3, $buttons);

        $btn1 = $buttons[0]['reply'] ?? $buttons[0];
        $btn2 = $buttons[1]['reply'] ?? $buttons[1];
        $btn3 = $buttons[2]['reply'] ?? $buttons[2];

        $this->assertEquals('main_new_appointment', $btn1['id']);
        $this->assertEquals('New Appointment', $btn1['title']);

        $this->assertEquals('main_view_appointments', $btn2['id']);
        $this->assertEquals('View / Reschedule Appointments', $btn2['title']);

        $this->assertEquals('main_cancel_appointments', $btn3['id']);
        $this->assertEquals('Cancel Active Appointments', $btn3['title']);
    }

    /** 2. Patient selection displays only the latest patient */
    public function testPatientSelectionDisplaysOnlyTheLatestPatient(): void
    {
        $phone = '905559998877';
        $olderPatient = Patient::factory()->create([
            'primary_phone' => $phone,
            'full_name' => 'First Older Patient',
            'updated_at' => Carbon::now()->subDays(2),
        ]);
        $latestPatient = Patient::factory()->create([
            'primary_phone' => $phone,
            'full_name' => 'Latest Active Patient',
            'updated_at' => Carbon::now(),
        ]);

        $flow = $this->makeFlowService();
        $flow->handle($phone, 'main_new_appointment');

        $last = end($this->sentMessages);
        $this->assertEquals('button', $last['type']);
        $this->assertStringContainsString('Please select a patient:', $last['body']);

        $buttons = $last['buttons'];
        $this->assertCount(3, $buttons);

        $btn1 = $buttons[0]['reply'] ?? $buttons[0];
        $btn2 = $buttons[1]['reply'] ?? $buttons[1];
        $btn3 = $buttons[2]['reply'] ?? $buttons[2];

        // Only the latest patient name is shown on Button 1
        $this->assertEquals('patient_latest', $btn1['id']);
        $this->assertEquals('Latest Active Patient', $btn1['title']);
        $this->assertNotEquals('First Older Patient', $btn1['title']);

        $this->assertEquals('patient_new', $btn2['id']);
        $this->assertEquals('Register New Patient', $btn2['title']);

        $this->assertEquals('flow_cancel', $btn3['id']);
        $this->assertEquals('Cancel and Return to Main Menu', $btn3['title']);
    }

    /** 3. New patient registration updates the latest patient */
    public function testNewPatientRegistrationUpdatesTheLatestPatient(): void
    {
        $phone = '905552223344';
        $flow = $this->makeFlowService();

        // Start booking and choose to register new patient
        $flow->handle($phone, 'main_new_appointment');
        $flow->handle($phone, 'patient_new');

        $last = end($this->sentMessages);
        $this->assertEquals('text', $last['type']);
        $this->assertStringContainsString("Please enter the patient's full name:", $last['msg']);

        // Send new patient name
        $flow->handle($phone, 'Tariq Al-Mansoor');

        $created = Patient::where('primary_phone', $phone)->where('full_name', 'Tariq Al-Mansoor')->first();
        $this->assertNotNull($created);

        // Next booking should now show Tariq as the latest patient
        $flow->handle($phone, 'main_menu');
        $flow->handle($phone, 'main_new_appointment');

        $last = end($this->sentMessages);
        $btn1 = $last['buttons'][0]['reply'] ?? $last['buttons'][0];
        $this->assertEquals('Tariq Al-Mansoor', $btn1['title']);
    }

    /** 4. No patient is displayed when no patient exists */
    public function testNoPatientIsDisplayedWhenNoPatientExists(): void
    {
        $phone = '905553334455';
        $flow = $this->makeFlowService();

        $flow->handle($phone, 'main_new_appointment');

        $last = end($this->sentMessages);
        $this->assertEquals('button', $last['type']);
        $this->assertStringContainsString('Please select a patient:', $last['body']);

        $buttons = $last['buttons'];
        // Should only have 2 buttons: Register New Patient & Cancel
        $this->assertCount(2, $buttons);

        $btn1 = $buttons[0]['reply'] ?? $buttons[0];
        $btn2 = $buttons[1]['reply'] ?? $buttons[1];

        $this->assertEquals('patient_new', $btn1['id']);
        $this->assertEquals('Register New Patient', $btn1['title']);

        $this->assertEquals('flow_cancel', $btn2['id']);
        $this->assertEquals('Cancel and Return to Main Menu', $btn2['title']);
    }

    /** 5. Clinic selection and separate cancel button work */
    public function testClinicSelectionAndSeparateCancelButtonWork(): void
    {
        $phone = '905554445566';
        $patient = Patient::factory()->create(['primary_phone' => $phone]);

        $flow = $this->makeFlowService();
        $session = WhatsappSession::firstOrCreate(
            ['phone_number' => $phone],
            ['current_state' => 'MAIN_MENU', 'state_payload' => []]
        );
        $session->update([
            'current_state' => 'SELECTING_PATIENT',
            'state_payload' => ['latest_patient_id' => $patient->id],
        ]);

        // Select latest patient -> prompts KVKK consent
        $flow->handle($phone, 'patient_latest');
        $flow->handle($phone, 'kvkk_yes');

        // Should have sent two messages: list of clinics, then separate cancel button
        $count = count($this->sentMessages);
        $this->assertGreaterThanOrEqual(2, $count);

        $listMsg = $this->sentMessages[$count - 2];
        $btnMsg = $this->sentMessages[$count - 1];

        $this->assertEquals('list', $listMsg['type']);
        $this->assertStringContainsString('Please select a clinic:', $listMsg['body']);

        // Verify cancel is NOT inside list
        $listRowIds = array_column($listMsg['sections'][0]['rows'], 'id');
        $this->assertNotContains('flow_cancel', $listRowIds);
        $this->assertNotContains('main_menu', $listRowIds);

        // Verify separate cancel button
        $this->assertEquals('button', $btnMsg['type']);
        $cancelBtn = $btnMsg['buttons'][0]['reply'] ?? $btnMsg['buttons'][0];
        $this->assertEquals('flow_cancel', $cancelBtn['id']);
        $this->assertEquals('Cancel and Return to Main Menu', $cancelBtn['title']);

        // Pressing cancel returns to main menu
        $flow->handle($phone, 'flow_cancel');
        $session->refresh();
        $this->assertEquals('MAIN_MENU', $session->current_state);
    }

    /** 6. Doctor selection and separate cancel button work */
    public function testDoctorSelectionAndSeparateCancelButtonWork(): void
    {
        $phone = '905555556677';
        $patient = Patient::factory()->create(['primary_phone' => $phone]);
        $specialty = Specialty::first();
        $doctor = Doctor::where('specialty_id', $specialty->id)->first();

        $flow = $this->makeFlowService();
        $session = WhatsappSession::firstOrCreate(
            ['phone_number' => $phone],
            ['current_state' => 'MAIN_MENU', 'state_payload' => []]
        );
        $session->update([
            'current_state' => 'SELECTING_SPECIALTY',
            'state_payload' => ['patient_id' => $patient->id, 'specialty_ids' => [$specialty->id]],
        ]);

        $flow->handle($phone, 'clinic_' . $specialty->id);

        $count = count($this->sentMessages);
        $listMsg = $this->sentMessages[$count - 2];
        $btnMsg = $this->sentMessages[$count - 1];

        $this->assertEquals('list', $listMsg['type']);
        $this->assertStringContainsString('Please select a doctor:', $listMsg['body']);

        $listRowIds = array_column($listMsg['sections'][0]['rows'], 'id');
        $this->assertNotContains('flow_cancel', $listRowIds);

        $this->assertEquals('button', $btnMsg['type']);
        $cancelBtn = $btnMsg['buttons'][0]['reply'] ?? $btnMsg['buttons'][0];
        $this->assertEquals('flow_cancel', $cancelBtn['id']);

        // Cancel test
        $flow->handle($phone, 'flow_cancel');
        $session->refresh();
        $this->assertEquals('MAIN_MENU', $session->current_state);
    }

    /** 7. Date selection and cancellation work */
    public function testDateSelectionAndCancellationWork(): void
    {
        $phone = '905556667788';
        $patient = Patient::factory()->create(['primary_phone' => $phone]);
        $doctor = Doctor::first();

        $flow = $this->makeFlowService();
        $session = WhatsappSession::firstOrCreate(
            ['phone_number' => $phone],
            ['current_state' => 'MAIN_MENU', 'state_payload' => []]
        );
        $session->update([
            'current_state' => 'SELECTING_DOCTOR',
            'state_payload' => ['patient_id' => $patient->id, 'doctor_ids' => [$doctor->id]],
        ]);

        $flow->handle($phone, 'doctor_' . $doctor->id);

        $count = count($this->sentMessages);
        $listMsg = $this->sentMessages[$count - 2];
        $btnMsg = $this->sentMessages[$count - 1];

        $this->assertEquals('list', $listMsg['type']);
        $this->assertStringContainsString('Please select a suitable appointment date:', $listMsg['body']);

        $this->assertEquals('button', $btnMsg['type']);
        $cancelBtn = $btnMsg['buttons'][0]['reply'] ?? $btnMsg['buttons'][0];
        $this->assertEquals('flow_cancel', $cancelBtn['id']);

        $flow->handle($phone, 'flow_cancel');
        $session->refresh();
        $this->assertEquals('MAIN_MENU', $session->current_state);
    }

    /** 8. Time selection and cancellation work */
    public function testTimeSelectionAndCancellationWork(): void
    {
        $phone = '905557778899';
        $patient = Patient::factory()->create(['primary_phone' => $phone]);
        $doctor = Doctor::first();

        $flow = $this->makeFlowService();
        $session = WhatsappSession::firstOrCreate(
            ['phone_number' => $phone],
            ['current_state' => 'MAIN_MENU', 'state_payload' => []]
        );
        $session->update([
            'current_state' => 'SELECTING_DATE',
            'state_payload' => [
                'patient_id' => $patient->id,
                'doctor_id' => $doctor->id,
                'available_dates' => [['date' => '2026-11-08', 'day_name' => 'Sunday', 'formatted' => '2026-11-08']],
            ],
        ]);

        $flow->handle($phone, 'date_2026-11-08');

        $count = count($this->sentMessages);
        $listMsg = $this->sentMessages[$count - 2];
        $btnMsg = $this->sentMessages[$count - 1];

        $this->assertEquals('list', $listMsg['type']);
        $this->assertStringContainsString('Please select an available appointment time:', $listMsg['body']);

        $this->assertEquals('button', $btnMsg['type']);
        $cancelBtn = $btnMsg['buttons'][0]['reply'] ?? $btnMsg['buttons'][0];
        $this->assertEquals('flow_cancel', $cancelBtn['id']);

        $flow->handle($phone, 'flow_cancel');
        $session->refresh();
        $this->assertEquals('MAIN_MENU', $session->current_state);
    }

    /** 9. Selecting a slot creates one temporary reservation */
    public function testSelectingSlotCreatesOneTemporaryReservation(): void
    {
        $phone = '905558889900';
        $patient = Patient::factory()->create(['primary_phone' => $phone, 'full_name' => 'Mohammed']);
        $doctor = Doctor::first();

        $flow = $this->makeFlowService();
        $session = WhatsappSession::firstOrCreate(
            ['phone_number' => $phone],
            ['current_state' => 'MAIN_MENU', 'state_payload' => []]
        );
        $session->update([
            'current_state' => 'SELECTING_SLOT',
            'state_payload' => [
                'patient_id' => $patient->id,
                'doctor_id' => $doctor->id,
                'selected_date' => '2026-11-08',
                'available_slots' => [
                    ['display' => '09:00 - 09:30', 'start_time' => '09:00:00', 'end_time' => '09:30:00'],
                ],
            ],
        ]);

        $flow->handle($phone, 'slot_0');

        $session->refresh();
        $this->assertEquals('CONFIRMING_BOOKING', $session->current_state);
        $this->assertNotEmpty($session->state_payload['appointment_id']);

        $holding = Appointment::find($session->state_payload['appointment_id']);
        $this->assertNotNull($holding);
        $this->assertEquals('holding', $holding->status);

        // Verify review confirmation message format
        $last = end($this->sentMessages);
        $this->assertEquals('button', $last['type']);
        $this->assertStringContainsString('Appointment Details', $last['body']);
        $this->assertStringContainsString('Patient: Mohammed', $last['body']);
        $this->assertStringContainsString('Do you want to proceed?', $last['body']);

        $buttons = $last['buttons'];
        $this->assertCount(2, $buttons);

        $btn1 = $buttons[0]['reply'] ?? $buttons[0];
        $btn2 = $buttons[1]['reply'] ?? $buttons[1];

        $this->assertEquals('booking_yes', $btn1['id']);
        $this->assertEquals('Yes', $btn1['title']);
        $this->assertEquals('booking_no', $btn2['id']);
        $this->assertEquals('No', $btn2['title']);
    }

    /** 10. Pressing Yes confirms the exact pending appointment */
    public function testPressingYesConfirmsTheExactPendingAppointment(): void
    {
        $phone = '905559990011';
        $patient = Patient::factory()->create(['primary_phone' => $phone, 'full_name' => 'Mohammed']);
        $doctor = Doctor::first();

        $holdingAppt = Appointment::create([
            'reference_code' => 'AP-TEST-CONFIRM',
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'appointment_date' => '2026-11-08',
            'start_time' => '09:00:00',
            'end_time' => '09:30:00',
            'status' => 'holding',
            'holding_expires_at' => Carbon::now()->addMinutes(10),
            'source' => 'whatsapp',
        ]);

        $flow = $this->makeFlowService();
        $session = WhatsappSession::firstOrCreate(
            ['phone_number' => $phone],
            ['current_state' => 'MAIN_MENU', 'state_payload' => []]
        );
        $session->update([
            'current_state' => 'CONFIRMING_BOOKING',
            'state_payload' => [
                'patient_id' => $patient->id,
                'doctor_id' => $doctor->id,
                'selected_date' => '2026-11-08',
                'appointment_id' => $holdingAppt->id,
                'slot_display' => '09:00 - 09:30',
            ],
        ]);

        // Press Yes
        $flow->handle($phone, 'booking_yes');

        $holdingAppt->refresh();
        $this->assertEquals('confirmed', $holdingAppt->status);

        // Verify clean confirmation message
        $count = count($this->sentMessages);
        $confMsg = $this->sentMessages[$count - 2];
        $mainMenuMsg = $this->sentMessages[$count - 1];

        $this->assertEquals('text', $confMsg['type']);
        $this->assertStringContainsString('Your appointment has been confirmed successfully.', $confMsg['msg']);
        $this->assertStringContainsString('Patient: Mohammed', $confMsg['msg']);
        $this->assertStringContainsString('Date: 2026-11-08', $confMsg['msg']);
        $this->assertStringContainsString('Time: 09:00 - 09:30', $confMsg['msg']);
        $this->assertStringContainsString('Thank you for choosing Al-Shifa Medical Clinic.', $confMsg['msg']);

        // Main menu automatically displayed
        $this->assertEquals('button', $mainMenuMsg['type']);
        $this->assertStringContainsString('Welcome to Al-Shifa Medical Clinic.', $mainMenuMsg['body']);

        $session->refresh();
        $this->assertEquals('MAIN_MENU', $session->current_state);
        $this->assertEmpty($session->state_payload);
    }

    /** 11. Pressing No releases the temporary reservation */
    public function testPressingNoReleasesTheTemporaryReservation(): void
    {
        $phone = '905550001122';
        $patient = Patient::factory()->create(['primary_phone' => $phone]);
        $doctor = Doctor::first();

        $holdingAppt = Appointment::create([
            'reference_code' => 'AP-TEST-REJECT',
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'appointment_date' => '2026-11-08',
            'start_time' => '10:00:00',
            'end_time' => '10:30:00',
            'status' => 'holding',
            'holding_expires_at' => Carbon::now()->addMinutes(10),
            'source' => 'whatsapp',
        ]);

        $flow = $this->makeFlowService();
        $session = WhatsappSession::firstOrCreate(
            ['phone_number' => $phone],
            ['current_state' => 'MAIN_MENU', 'state_payload' => []]
        );
        $session->update([
            'current_state' => 'CONFIRMING_BOOKING',
            'state_payload' => [
                'patient_id' => $patient->id,
                'doctor_id' => $doctor->id,
                'appointment_id' => $holdingAppt->id,
            ],
        ]);

        // Press No
        $flow->handle($phone, 'booking_no');

        $holdingAppt->refresh();
        $this->assertEquals('cancelled_by_patient', $holdingAppt->status);

        $session->refresh();
        $this->assertEquals('MAIN_MENU', $session->current_state);
    }

    /** 12. Confirmation does not return to time selection */
    public function testConfirmationDoesNotReturnToTimeSelection(): void
    {
        $phone = '905551113344';
        $patient = Patient::factory()->create(['primary_phone' => $phone, 'full_name' => 'Ahmad']);
        $doctor = Doctor::first();

        $holdingAppt = Appointment::create([
            'reference_code' => 'AP-TEST-NO-RETURN',
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'appointment_date' => '2026-11-08',
            'start_time' => '11:00:00',
            'end_time' => '11:30:00',
            'status' => 'holding',
            'holding_expires_at' => Carbon::now()->addMinutes(10),
            'source' => 'whatsapp',
        ]);

        $flow = $this->makeFlowService();
        $session = WhatsappSession::firstOrCreate(
            ['phone_number' => $phone],
            ['current_state' => 'MAIN_MENU', 'state_payload' => []]
        );
        $session->update([
            'current_state' => 'CONFIRMING_BOOKING',
            'state_payload' => [
                'patient_id' => $patient->id,
                'doctor_id' => $doctor->id,
                'selected_date' => '2026-11-08',
                'appointment_id' => $holdingAppt->id,
                'slot_display' => '11:00 - 11:30',
            ],
        ]);

        $flow->handle($phone, 'booking_yes');

        // Check that NO time selection list was sent
        foreach ($this->sentMessages as $msg) {
            if ($msg['type'] === 'list') {
                $this->assertStringNotContainsString('Please select an available appointment time:', $msg['body']);
            }
        }

        $session->refresh();
        $this->assertEquals('MAIN_MENU', $session->current_state);
    }

    /** 13. Repeated webhook delivery does not create duplicate appointments */
    public function testRepeatedWebhookDeliveryDoesNotCreateDuplicateAppointments(): void
    {
        $phone = '905552224455';
        $patient = Patient::factory()->create(['primary_phone' => $phone]);
        $doctor = Doctor::first();

        $holdingAppt = Appointment::create([
            'reference_code' => 'AP-TEST-IDEMP',
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'appointment_date' => '2026-11-08',
            'start_time' => '12:00:00',
            'end_time' => '12:30:00',
            'status' => 'holding',
            'holding_expires_at' => Carbon::now()->addMinutes(10),
            'source' => 'whatsapp',
        ]);

        $flow = $this->makeFlowService();
        $session = WhatsappSession::firstOrCreate(
            ['phone_number' => $phone],
            ['current_state' => 'MAIN_MENU', 'state_payload' => []]
        );
        $session->update([
            'current_state' => 'CONFIRMING_BOOKING',
            'state_payload' => [
                'patient_id' => $patient->id,
                'doctor_id' => $doctor->id,
                'selected_date' => '2026-11-08',
                'appointment_id' => $holdingAppt->id,
                'slot_display' => '12:00 - 12:30',
            ],
        ]);

        // First delivery
        $flow->handle($phone, 'booking_yes');
        $this->assertEquals('confirmed', $holdingAppt->fresh()->status);
        $countAfterFirst = Appointment::where('patient_id', $patient->id)->count();

        // Second duplicate delivery with the same state_payload
        $session->update([
            'current_state' => 'CONFIRMING_BOOKING',
            'state_payload' => [
                'patient_id' => $patient->id,
                'doctor_id' => $doctor->id,
                'selected_date' => '2026-11-08',
                'appointment_id' => $holdingAppt->id,
                'slot_display' => '12:00 - 12:30',
            ],
        ]);
        $flow->handle($phone, 'booking_yes');

        // Appointment count must remain unchanged
        $countAfterSecond = Appointment::where('patient_id', $patient->id)->count();
        $this->assertEquals($countAfterFirst, $countAfterSecond);
    }

    /** 14. KVKK consent remains required for every new booking */
    public function testKvkkConsentRemainsRequiredForEveryNewBooking(): void
    {
        $phone = '905553335566';
        $patient = Patient::factory()->create([
            'primary_phone' => $phone,
            'kvkk_consent_accepted' => true,
        ]);

        $flow = $this->makeFlowService();
        $flow->handle($phone, 'main_new_appointment');

        // Select the existing patient
        $flow->handle($phone, 'patient_latest');

        // Bot must NOT bypass KVKK consent even though patient already had consent
        $last = end($this->sentMessages);
        $this->assertEquals('button', $last['type']);
        $this->assertStringContainsString('Personal Data Protection (KVKK)', $last['body']);

        $btn1 = $last['buttons'][0]['reply'] ?? $last['buttons'][0];
        $btn2 = $last['buttons'][1]['reply'] ?? $last['buttons'][1];
        $this->assertEquals('kvkk_yes', $btn1['id']);
        $this->assertEquals('kvkk_no', $btn2['id']);

        $session = WhatsappSession::where('phone_number', $phone)->first();
        $this->assertEquals('AWAITING_KVKK_CONSENT', $session->current_state);
    }

    /** 15. Canceling the flow does not delete or cancel confirmed appointments */
    public function testCancelingFlowDoesNotDeleteConfirmedAppointments(): void
    {
        $phone = '905554446677';
        $patient = Patient::factory()->create(['primary_phone' => $phone]);
        $doctor = Doctor::first();

        // Already confirmed past appointment
        $confirmedAppt = Appointment::create([
            'reference_code' => 'AP-CONFIRMED-KEEP',
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'appointment_date' => '2026-11-09',
            'start_time' => '14:00:00',
            'end_time' => '14:30:00',
            'status' => 'confirmed',
            'source' => 'whatsapp',
        ]);

        // New temporary hold
        $holdingAppt = Appointment::create([
            'reference_code' => 'AP-HOLD-CANCEL',
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'appointment_date' => '2026-11-10',
            'start_time' => '15:00:00',
            'end_time' => '15:30:00',
            'status' => 'holding',
            'source' => 'whatsapp',
        ]);

        $flow = $this->makeFlowService();
        $session = WhatsappSession::firstOrCreate(
            ['phone_number' => $phone],
            ['current_state' => 'MAIN_MENU', 'state_payload' => []]
        );
        $session->update([
            'current_state' => 'CONFIRMING_BOOKING',
            'state_payload' => ['appointment_id' => $holdingAppt->id],
        ]);

        // User cancels current booking flow
        $flow->handle($phone, 'flow_cancel');

        // Confirmed appointment must still be confirmed
        $this->assertEquals('confirmed', $confirmedAppt->fresh()->status);

        // Holding appointment is cancelled
        $this->assertEquals('cancelled_by_patient', $holdingAppt->fresh()->status);
    }

    /** 16. Expired reservations cannot be confirmed */
    public function testExpiredReservationsCannotBeConfirmed(): void
    {
        $phone = '905555557788';
        $patient = Patient::factory()->create(['primary_phone' => $phone]);
        $doctor = Doctor::first();

        // Expired hold (created 15 minutes ago, expires in 10 minutes from creation)
        $expiredAppt = Appointment::create([
            'reference_code' => 'AP-EXPIRED',
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'appointment_date' => '2026-11-08',
            'start_time' => '16:00:00',
            'end_time' => '16:30:00',
            'status' => 'holding',
            'holding_expires_at' => Carbon::now()->subMinutes(5),
            'source' => 'whatsapp',
        ]);

        $flow = $this->makeFlowService();
        $session = WhatsappSession::firstOrCreate(
            ['phone_number' => $phone],
            ['current_state' => 'MAIN_MENU', 'state_payload' => []]
        );
        $session->update([
            'current_state' => 'CONFIRMING_BOOKING',
            'state_payload' => [
                'patient_id' => $patient->id,
                'doctor_id' => $doctor->id,
                'selected_date' => '2026-11-08',
                'appointment_id' => $expiredAppt->id,
                'available_dates' => [['date' => '2026-11-08', 'day_name' => 'Sunday', 'formatted' => '2026-11-08']],
            ],
        ]);

        $flow->handle($phone, 'booking_yes');

        // Must not be confirmed
        $expiredAppt->refresh();
        $this->assertNotEquals('confirmed', $expiredAppt->status);
        $this->assertEquals('cancelled_by_clinic', $expiredAppt->status);

        // User informed of expiration
        $hasExpirationMsg = false;
        foreach ($this->sentMessages as $m) {
            if (isset($m['msg']) && str_contains($m['msg'], 'expired')) {
                $hasExpirationMsg = true;
                break;
            }
        }
        $this->assertTrue($hasExpirationMsg);
    }

    /** 17. Slot selection does not process when bot expects confirmation */
    public function testCannotSelectAnotherSlotWhileConfirmationIsPending(): void
    {
        $phone = '905556668899';
        $patient = Patient::factory()->create(['primary_phone' => $phone]);
        $doctor = Doctor::first();

        $holdingAppt = Appointment::create([
            'reference_code' => 'AP-HOLD-PENDING',
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'appointment_date' => '2026-11-08',
            'start_time' => '09:00:00',
            'end_time' => '09:30:00',
            'status' => 'holding',
            'holding_expires_at' => Carbon::now()->addMinutes(10),
            'source' => 'whatsapp',
        ]);

        $flow = $this->makeFlowService();
        $session = WhatsappSession::firstOrCreate(
            ['phone_number' => $phone],
            ['current_state' => 'MAIN_MENU', 'state_payload' => []]
        );
        $session->update([
            'current_state' => 'CONFIRMING_BOOKING',
            'state_payload' => [
                'patient_id' => $patient->id,
                'doctor_id' => $doctor->id,
                'selected_date' => '2026-11-08',
                'appointment_id' => $holdingAppt->id,
                'available_slots' => [
                    ['display' => '09:00 - 09:30', 'start_time' => '09:00:00', 'end_time' => '09:30:00'],
                    ['display' => '09:30 - 10:00', 'start_time' => '09:30:00', 'end_time' => '10:00:00'],
                ],
            ],
        ]);

        // Attempting to select slot_1 while in CONFIRMING_BOOKING
        $flow->handle($phone, 'slot_1');

        // Session must remain in CONFIRMING_BOOKING
        $session->refresh();
        $this->assertEquals('CONFIRMING_BOOKING', $session->current_state);
        // Appointment ID must remain the same
        $this->assertEquals($holdingAppt->id, $session->state_payload['appointment_id']);
    }

    /** 18. Slot pagination traverses slots with separate cancel button */
    public function testSlotPaginationTraversesSlotsWithSeparateCancelButton(): void
    {
        $totalSlots = 20;
        $flow = $this->makeFlowService($totalSlots);

        $patient = Patient::factory()->create(['primary_phone' => '905557770011']);
        $doctor = Doctor::first();

        $session = WhatsappSession::firstOrCreate(
            ['phone_number' => $patient->primary_phone],
            ['current_state' => 'MAIN_MENU', 'state_payload' => []]
        );
        $session->update([
            'current_state' => 'SELECTING_DATE',
            'state_payload' => [
                'patient_id' => $patient->id,
                'doctor_id' => $doctor->id,
                'available_dates' => [['date' => '2026-11-08', 'day_name' => 'Sunday', 'formatted' => '2026-11-08']],
            ],
        ]);

        // Choose date -> renders slots list + separate cancel button
        $flow->handle($patient->primary_phone, 'date_2026-11-08');

        $count = count($this->sentMessages);
        $listMsg = $this->sentMessages[$count - 2];
        $btnMsg = $this->sentMessages[$count - 1];

        $this->assertEquals('list', $listMsg['type']);
        $rows = $listMsg['sections'][0]['rows'];
        $this->assertLessThanOrEqual(10, count($rows));

        // Rows in page 1: 8 slots + slot_page_next (no cancel in list)
        $rowIds = array_column($rows, 'id');
        $this->assertNotContains('flow_cancel', $rowIds);
        $this->assertContains('slot_page_next', $rowIds);

        // Separate cancel button
        $this->assertEquals('button', $btnMsg['type']);
        $cancelBtn = $btnMsg['buttons'][0]['reply'] ?? $btnMsg['buttons'][0];
        $this->assertEquals('flow_cancel', $cancelBtn['id']);

        // Next page
        $flow->handle($patient->primary_phone, 'slot_page_next');
        $count2 = count($this->sentMessages);
        $page2List = $this->sentMessages[$count2 - 2];
        $page2Rows = $page2List['sections'][0]['rows'];
        $this->assertLessThanOrEqual(10, count($page2Rows));

        $page2Ids = array_column($page2Rows, 'id');
        $this->assertContains('slot_page_prev', $page2Ids);
    }
}
