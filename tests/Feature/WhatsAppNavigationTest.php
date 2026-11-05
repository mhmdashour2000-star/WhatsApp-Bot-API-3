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
        /**
     * Focused test: Confirm KVKK consent is persisted on the appointment record and a new booking again asks for consent.
     */
    public function testConfirmedAppointmentStoresKvkkConsentAtAppointmentLevelAndRequiresConsentOnNewBooking(): void
    {
        $phone = '905556667788';
        $patient = Patient::factory()->create([
            'primary_phone' => $phone,
            'kvkk_consent_accepted' => false,
        ]);
        $doctor = Doctor::first();

        // Create a holding appointment directly (status = holding)
        $holdingAppt = Appointment::create([
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'appointment_date' => Carbon::today()->addDays(1)->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '10:30:00',
            'status' => 'holding',
        ]);

        // Initialise session in confirmation state, mimicking flow after KVKK acceptance.
        $session = WhatsappSession::firstOrCreate(
            ['phone_number' => $phone],
            ['current_state' => 'MAIN_MENU', 'state_payload' => []]
        );
        $session->update([
            'current_state' => 'CONFIRMING_BOOKING',
            'state_payload' => [
                'patient_id' => $patient->id,
                'doctor_id' => $doctor->id,
                'selected_date' => $holdingAppt->appointment_date,
                'appointment_id' => $holdingAppt->id,
                'slot_display' => $holdingAppt->start_time . ' - ' . $holdingAppt->end_time,
                'kvkk_accepted' => true,
            ],
        ]);

        $flow = $this->makeFlowService();
        // Simulate user confirming the booking.
        $flow->handle($phone, 'booking_yes');

        // Refresh appointment and assert KVKK consent persisted on appointment.
        $holdingAppt->refresh();
        $this->assertTrue($holdingAppt->kvkk_consent_accepted);
        $this->assertNotNull($holdingAppt->kvkk_consent_date);

        // Start a second booking for the same patient.
        $flow->handle($phone, 'main_new_appointment');
        // Choose existing patient (latest)
        $flow->handle($phone, 'patient_latest');
        // Session should now be awaiting KVKK consent again.
        $session->refresh();
        $this->assertEquals('AWAITING_KVKK_CONSENT', $session->current_state);
    }
}

    protected function makeFlowService(int $slotCount = 10, ?AppointmentBookingService $bookingService = null): WhatsAppFlowService
    {
        if ($bookingService) {
            return new WhatsAppFlowService($this->fakeApi, $bookingService);
            /**
     * Focused test: Confirm KVKK consent is persisted on the appointment record and a new booking again asks for consent.
     */
    public function testConfirmedAppointmentStoresKvkkConsentAtAppointmentLevelAndRequiresConsentOnNewBooking(): void
    {
        $phone = '905556667788';
        $patient = Patient::factory()->create([
            'primary_phone' => $phone,
            'kvkk_consent_accepted' => false,
        ]);
        $doctor = Doctor::first();

        // Create a holding appointment directly (status = holding)
        $holdingAppt = Appointment::create([
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'appointment_date' => Carbon::today()->addDays(1)->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '10:30:00',
            'status' => 'holding',
        ]);

        // Initialise session in confirmation state, mimicking flow after KVKK acceptance.
        $session = WhatsappSession::firstOrCreate(
            ['phone_number' => $phone],
            ['current_state' => 'MAIN_MENU', 'state_payload' => []]
        );
        $session->update([
            'current_state' => 'CONFIRMING_BOOKING',
            'state_payload' => [
                'patient_id' => $patient->id,
                'doctor_id' => $doctor->id,
                'selected_date' => $holdingAppt->appointment_date,
                'appointment_id' => $holdingAppt->id,
                'slot_display' => $holdingAppt->start_time . ' - ' . $holdingAppt->end_time,
                'kvkk_accepted' => true,
            ],
        ]);

        $flow = $this->makeFlowService();
        // Simulate user confirming the booking.
        $flow->handle($phone, 'booking_yes');

        // Refresh appointment and assert KVKK consent persisted on appointment.
        $holdingAppt->refresh();
        $this->assertTrue($holdingAppt->kvkk_consent_accepted);
        $this->assertNotNull($holdingAppt->kvkk_consent_date);

        // Start a second booking for the same patient.
        $flow->handle($phone, 'main_new_appointment');
        // Choose existing patient (latest)
        $flow->handle($phone, 'patient_latest');
        // Session should now be awaiting KVKK consent again.
        $session->refresh();
        $this->assertEquals('AWAITING_KVKK_CONSENT', $session->current_state);
    }
}

        $bookingMock = Mockery::mock(AppointmentBookingService::class);
        $bookingMock->shouldReceive('getAvailableDates')->andReturn(collect([
            ['date' => '2026-11-08', 'day_name' => 'الأحد', 'formatted' => '2026-11-08'],
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
            /**
     * Focused test: Confirm KVKK consent is persisted on the appointment record and a new booking again asks for consent.
     */
    public function testConfirmedAppointmentStoresKvkkConsentAtAppointmentLevelAndRequiresConsentOnNewBooking(): void
    {
        $phone = '905556667788';
        $patient = Patient::factory()->create([
            'primary_phone' => $phone,
            'kvkk_consent_accepted' => false,
        ]);
        $doctor = Doctor::first();

        // Create a holding appointment directly (status = holding)
        $holdingAppt = Appointment::create([
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'appointment_date' => Carbon::today()->addDays(1)->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '10:30:00',
            'status' => 'holding',
        ]);

        // Initialise session in confirmation state, mimicking flow after KVKK acceptance.
        $session = WhatsappSession::firstOrCreate(
            ['phone_number' => $phone],
            ['current_state' => 'MAIN_MENU', 'state_payload' => []]
        );
        $session->update([
            'current_state' => 'CONFIRMING_BOOKING',
            'state_payload' => [
                'patient_id' => $patient->id,
                'doctor_id' => $doctor->id,
                'selected_date' => $holdingAppt->appointment_date,
                'appointment_id' => $holdingAppt->id,
                'slot_display' => $holdingAppt->start_time . ' - ' . $holdingAppt->end_time,
                'kvkk_accepted' => true,
            ],
        ]);

        $flow = $this->makeFlowService();
        // Simulate user confirming the booking.
        $flow->handle($phone, 'booking_yes');

        // Refresh appointment and assert KVKK consent persisted on appointment.
        $holdingAppt->refresh();
        $this->assertTrue($holdingAppt->kvkk_consent_accepted);
        $this->assertNotNull($holdingAppt->kvkk_consent_date);

        // Start a second booking for the same patient.
        $flow->handle($phone, 'main_new_appointment');
        // Choose existing patient (latest)
        $flow->handle($phone, 'patient_latest');
        // Session should now be awaiting KVKK consent again.
        $session->refresh();
        $this->assertEquals('AWAITING_KVKK_CONSENT', $session->current_state);
    }
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
        /**
     * Focused test: Confirm KVKK consent is persisted on the appointment record and a new booking again asks for consent.
     */
    public function testConfirmedAppointmentStoresKvkkConsentAtAppointmentLevelAndRequiresConsentOnNewBooking(): void
    {
        $phone = '905556667788';
        $patient = Patient::factory()->create([
            'primary_phone' => $phone,
            'kvkk_consent_accepted' => false,
        ]);
        $doctor = Doctor::first();

        // Create a holding appointment directly (status = holding)
        $holdingAppt = Appointment::create([
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'appointment_date' => Carbon::today()->addDays(1)->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '10:30:00',
            'status' => 'holding',
        ]);

        // Initialise session in confirmation state, mimicking flow after KVKK acceptance.
        $session = WhatsappSession::firstOrCreate(
            ['phone_number' => $phone],
            ['current_state' => 'MAIN_MENU', 'state_payload' => []]
        );
        $session->update([
            'current_state' => 'CONFIRMING_BOOKING',
            'state_payload' => [
                'patient_id' => $patient->id,
                'doctor_id' => $doctor->id,
                'selected_date' => $holdingAppt->appointment_date,
                'appointment_id' => $holdingAppt->id,
                'slot_display' => $holdingAppt->start_time . ' - ' . $holdingAppt->end_time,
                'kvkk_accepted' => true,
            ],
        ]);

        $flow = $this->makeFlowService();
        // Simulate user confirming the booking.
        $flow->handle($phone, 'booking_yes');

        // Refresh appointment and assert KVKK consent persisted on appointment.
        $holdingAppt->refresh();
        $this->assertTrue($holdingAppt->kvkk_consent_accepted);
        $this->assertNotNull($holdingAppt->kvkk_consent_date);

        // Start a second booking for the same patient.
        $flow->handle($phone, 'main_new_appointment');
        // Choose existing patient (latest)
        $flow->handle($phone, 'patient_latest');
        // Session should now be awaiting KVKK consent again.
        $session->refresh();
        $this->assertEquals('AWAITING_KVKK_CONSENT', $session->current_state);
    }
}

    /** 1. Main menu displays the three required buttons in Arabic */
    public function testMainMenuDisplaysTheThreeRequiredButtons(): void
    {
        $flow = $this->makeFlowService();
        $flow->handle('905551112233', 'menu');

        $this->assertNotEmpty($this->sentMessages);
        $last = end($this->sentMessages);

        $this->assertEquals('button', $last['type']);
        $this->assertStringContainsString("مرحباً بكم في عيادة الشفاء الطبية.", $last['body']);
        $this->assertStringContainsString("تتيح لكم هذه الخدمة إدارة مواعيدكم الطبية بكل سهولة.", $last['body']);

        $buttons = $last['buttons'];
        $this->assertCount(3, $buttons);

        $btn1 = $buttons[0]['reply'] ?? $buttons[0];
        $btn2 = $buttons[1]['reply'] ?? $buttons[1];
        $btn3 = $buttons[2]['reply'] ?? $buttons[2];

        $this->assertEquals('main_new_appointment', $btn1['id']);
        $this->assertEquals('حجز موعد جديد', $btn1['title']);

        $this->assertEquals('main_view_appointments', $btn2['id']);
        $this->assertEquals('استعراض المواعيد', $btn2['title']);

        $this->assertEquals('main_cancel_appointments', $btn3['id']);
        $this->assertEquals('إلغاء موعد نشط', $btn3['title']);
        /**
     * Focused test: Confirm KVKK consent is persisted on the appointment record and a new booking again asks for consent.
     */
    public function testConfirmedAppointmentStoresKvkkConsentAtAppointmentLevelAndRequiresConsentOnNewBooking(): void
    {
        $phone = '905556667788';
        $patient = Patient::factory()->create([
            'primary_phone' => $phone,
            'kvkk_consent_accepted' => false,
        ]);
        $doctor = Doctor::first();

        // Create a holding appointment directly (status = holding)
        $holdingAppt = Appointment::create([
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'appointment_date' => Carbon::today()->addDays(1)->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '10:30:00',
            'status' => 'holding',
        ]);

        // Initialise session in confirmation state, mimicking flow after KVKK acceptance.
        $session = WhatsappSession::firstOrCreate(
            ['phone_number' => $phone],
            ['current_state' => 'MAIN_MENU', 'state_payload' => []]
        );
        $session->update([
            'current_state' => 'CONFIRMING_BOOKING',
            'state_payload' => [
                'patient_id' => $patient->id,
                'doctor_id' => $doctor->id,
                'selected_date' => $holdingAppt->appointment_date,
                'appointment_id' => $holdingAppt->id,
                'slot_display' => $holdingAppt->start_time . ' - ' . $holdingAppt->end_time,
                'kvkk_accepted' => true,
            ],
        ]);

        $flow = $this->makeFlowService();
        // Simulate user confirming the booking.
        $flow->handle($phone, 'booking_yes');

        // Refresh appointment and assert KVKK consent persisted on appointment.
        $holdingAppt->refresh();
        $this->assertTrue($holdingAppt->kvkk_consent_accepted);
        $this->assertNotNull($holdingAppt->kvkk_consent_date);

        // Start a second booking for the same patient.
        $flow->handle($phone, 'main_new_appointment');
        // Choose existing patient (latest)
        $flow->handle($phone, 'patient_latest');
        // Session should now be awaiting KVKK consent again.
        $session->refresh();
        $this->assertEquals('AWAITING_KVKK_CONSENT', $session->current_state);
    }
}

    /** 2. Patient selection displays only the latest patient in Arabic */
    public function testPatientSelectionDisplaysOnlyTheLatestPatient(): void
    {
        $phone = '905559998877';
        $olderPatient = Patient::factory()->create([
            'primary_phone' => $phone,
            'full_name' => 'خالد السعيد',
            'updated_at' => Carbon::now()->subDays(2),
        ]);
        $latestPatient = Patient::factory()->create([
            'primary_phone' => $phone,
            'full_name' => 'سامي العلي',
            'updated_at' => Carbon::now(),
        ]);

        $flow = $this->makeFlowService();
        $flow->handle($phone, 'main_new_appointment');

        $last = end($this->sentMessages);
        $this->assertEquals('button', $last['type']);
        $this->assertStringContainsString('يرجى اختيار المريض:', $last['body']);

        $buttons = $last['buttons'];
        $this->assertCount(3, $buttons);

        $btn1 = $buttons[0]['reply'] ?? $buttons[0];
        $btn2 = $buttons[1]['reply'] ?? $buttons[1];
        $btn3 = $buttons[2]['reply'] ?? $buttons[2];

        // Only the latest patient name is shown on Button 1
        $this->assertEquals('patient_latest', $btn1['id']);
        $this->assertEquals('سامي العلي', $btn1['title']);
        $this->assertNotEquals('خالد السعيد', $btn1['title']);

        $this->assertEquals('patient_new', $btn2['id']);
        $this->assertEquals('تسجيل مريض جديد', $btn2['title']);

        $this->assertEquals('flow_cancel', $btn3['id']);
        $this->assertEquals('إلغاء والعودة', $btn3['title']);
        /**
     * Focused test: Confirm KVKK consent is persisted on the appointment record and a new booking again asks for consent.
     */
    public function testConfirmedAppointmentStoresKvkkConsentAtAppointmentLevelAndRequiresConsentOnNewBooking(): void
    {
        $phone = '905556667788';
        $patient = Patient::factory()->create([
            'primary_phone' => $phone,
            'kvkk_consent_accepted' => false,
        ]);
        $doctor = Doctor::first();

        // Create a holding appointment directly (status = holding)
        $holdingAppt = Appointment::create([
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'appointment_date' => Carbon::today()->addDays(1)->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '10:30:00',
            'status' => 'holding',
        ]);

        // Initialise session in confirmation state, mimicking flow after KVKK acceptance.
        $session = WhatsappSession::firstOrCreate(
            ['phone_number' => $phone],
            ['current_state' => 'MAIN_MENU', 'state_payload' => []]
        );
        $session->update([
            'current_state' => 'CONFIRMING_BOOKING',
            'state_payload' => [
                'patient_id' => $patient->id,
                'doctor_id' => $doctor->id,
                'selected_date' => $holdingAppt->appointment_date,
                'appointment_id' => $holdingAppt->id,
                'slot_display' => $holdingAppt->start_time . ' - ' . $holdingAppt->end_time,
                'kvkk_accepted' => true,
            ],
        ]);

        $flow = $this->makeFlowService();
        // Simulate user confirming the booking.
        $flow->handle($phone, 'booking_yes');

        // Refresh appointment and assert KVKK consent persisted on appointment.
        $holdingAppt->refresh();
        $this->assertTrue($holdingAppt->kvkk_consent_accepted);
        $this->assertNotNull($holdingAppt->kvkk_consent_date);

        // Start a second booking for the same patient.
        $flow->handle($phone, 'main_new_appointment');
        // Choose existing patient (latest)
        $flow->handle($phone, 'patient_latest');
        // Session should now be awaiting KVKK consent again.
        $session->refresh();
        $this->assertEquals('AWAITING_KVKK_CONSENT', $session->current_state);
    }
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
        $this->assertStringContainsString("يرجى إدخال الاسم الكامل للمريض:", $last['msg']);

        // Send new patient name
        $flow->handle($phone, 'طارق المنصور');

        $created = Patient::where('primary_phone', $phone)->where('full_name', 'طارق المنصور')->first();
        $this->assertNotNull($created);

        // Next booking should now show Tariq as the latest patient
        $flow->handle($phone, 'main_menu');
        $flow->handle($phone, 'main_new_appointment');

        $last = end($this->sentMessages);
        $btn1 = $last['buttons'][0]['reply'] ?? $last['buttons'][0];
        $this->assertEquals('طارق المنصور', $btn1['title']);
        /**
     * Focused test: Confirm KVKK consent is persisted on the appointment record and a new booking again asks for consent.
     */
    public function testConfirmedAppointmentStoresKvkkConsentAtAppointmentLevelAndRequiresConsentOnNewBooking(): void
    {
        $phone = '905556667788';
        $patient = Patient::factory()->create([
            'primary_phone' => $phone,
            'kvkk_consent_accepted' => false,
        ]);
        $doctor = Doctor::first();

        // Create a holding appointment directly (status = holding)
        $holdingAppt = Appointment::create([
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'appointment_date' => Carbon::today()->addDays(1)->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '10:30:00',
            'status' => 'holding',
        ]);

        // Initialise session in confirmation state, mimicking flow after KVKK acceptance.
        $session = WhatsappSession::firstOrCreate(
            ['phone_number' => $phone],
            ['current_state' => 'MAIN_MENU', 'state_payload' => []]
        );
        $session->update([
            'current_state' => 'CONFIRMING_BOOKING',
            'state_payload' => [
                'patient_id' => $patient->id,
                'doctor_id' => $doctor->id,
                'selected_date' => $holdingAppt->appointment_date,
                'appointment_id' => $holdingAppt->id,
                'slot_display' => $holdingAppt->start_time . ' - ' . $holdingAppt->end_time,
                'kvkk_accepted' => true,
            ],
        ]);

        $flow = $this->makeFlowService();
        // Simulate user confirming the booking.
        $flow->handle($phone, 'booking_yes');

        // Refresh appointment and assert KVKK consent persisted on appointment.
        $holdingAppt->refresh();
        $this->assertTrue($holdingAppt->kvkk_consent_accepted);
        $this->assertNotNull($holdingAppt->kvkk_consent_date);

        // Start a second booking for the same patient.
        $flow->handle($phone, 'main_new_appointment');
        // Choose existing patient (latest)
        $flow->handle($phone, 'patient_latest');
        // Session should now be awaiting KVKK consent again.
        $session->refresh();
        $this->assertEquals('AWAITING_KVKK_CONSENT', $session->current_state);
    }
}

    /** 4. No patient is displayed when no patient exists */
    public function testNoPatientIsDisplayedWhenNoPatientExists(): void
    {
        $phone = '905553334455';
        $flow = $this->makeFlowService();

        $flow->handle($phone, 'main_new_appointment');

        $last = end($this->sentMessages);
        $this->assertEquals('button', $last['type']);
        $this->assertStringContainsString('يرجى اختيار المريض:', $last['body']);

        $buttons = $last['buttons'];
        // Should only have 2 buttons: Register New Patient & Cancel
        $this->assertCount(2, $buttons);

        $btn1 = $buttons[0]['reply'] ?? $buttons[0];
        $btn2 = $buttons[1]['reply'] ?? $buttons[1];

        $this->assertEquals('patient_new', $btn1['id']);
        $this->assertEquals('تسجيل مريض جديد', $btn1['title']);

        $this->assertEquals('flow_cancel', $btn2['id']);
        $this->assertEquals('إلغاء والعودة', $btn2['title']);
        /**
     * Focused test: Confirm KVKK consent is persisted on the appointment record and a new booking again asks for consent.
     */
    public function testConfirmedAppointmentStoresKvkkConsentAtAppointmentLevelAndRequiresConsentOnNewBooking(): void
    {
        $phone = '905556667788';
        $patient = Patient::factory()->create([
            'primary_phone' => $phone,
            'kvkk_consent_accepted' => false,
        ]);
        $doctor = Doctor::first();

        // Create a holding appointment directly (status = holding)
        $holdingAppt = Appointment::create([
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'appointment_date' => Carbon::today()->addDays(1)->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '10:30:00',
            'status' => 'holding',
        ]);

        // Initialise session in confirmation state, mimicking flow after KVKK acceptance.
        $session = WhatsappSession::firstOrCreate(
            ['phone_number' => $phone],
            ['current_state' => 'MAIN_MENU', 'state_payload' => []]
        );
        $session->update([
            'current_state' => 'CONFIRMING_BOOKING',
            'state_payload' => [
                'patient_id' => $patient->id,
                'doctor_id' => $doctor->id,
                'selected_date' => $holdingAppt->appointment_date,
                'appointment_id' => $holdingAppt->id,
                'slot_display' => $holdingAppt->start_time . ' - ' . $holdingAppt->end_time,
                'kvkk_accepted' => true,
            ],
        ]);

        $flow = $this->makeFlowService();
        // Simulate user confirming the booking.
        $flow->handle($phone, 'booking_yes');

        // Refresh appointment and assert KVKK consent persisted on appointment.
        $holdingAppt->refresh();
        $this->assertTrue($holdingAppt->kvkk_consent_accepted);
        $this->assertNotNull($holdingAppt->kvkk_consent_date);

        // Start a second booking for the same patient.
        $flow->handle($phone, 'main_new_appointment');
        // Choose existing patient (latest)
        $flow->handle($phone, 'patient_latest');
        // Session should now be awaiting KVKK consent again.
        $session->refresh();
        $this->assertEquals('AWAITING_KVKK_CONSENT', $session->current_state);
    }
}

    /** 5. Clinic selection and separate cancel button work in Arabic */
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

        // Select latest patient -> prompts explicit consent
        $flow->handle($phone, 'patient_latest');
        $flow->handle($phone, 'kvkk_yes');

        // Should have sent two messages: list of clinics, then separate cancel button
        $count = count($this->sentMessages);
        $this->assertGreaterThanOrEqual(2, $count);

        $listMsg = $this->sentMessages[$count - 2];
        $btnMsg = $this->sentMessages[$count - 1];

        $this->assertEquals('list', $listMsg['type']);
        $this->assertStringContainsString('يرجى اختيار العيادة:', $listMsg['body']);
        $this->assertEquals('اختيار العيادة', $listMsg['buttonText']);

        // Verify cancel is NOT inside list
        $listRowIds = array_column($listMsg['sections'][0]['rows'], 'id');
        $this->assertNotContains('flow_cancel', $listRowIds);
        $this->assertNotContains('main_menu', $listRowIds);

        // Verify separate cancel button
        $this->assertEquals('button', $btnMsg['type']);
        $cancelBtn = $btnMsg['buttons'][0]['reply'] ?? $btnMsg['buttons'][0];
        $this->assertEquals('flow_cancel', $cancelBtn['id']);
        $this->assertEquals('إلغاء والعودة', $cancelBtn['title']);

        // Pressing cancel returns to main menu
        $flow->handle($phone, 'flow_cancel');
        $session->refresh();
        $this->assertEquals('MAIN_MENU', $session->current_state);
        /**
     * Focused test: Confirm KVKK consent is persisted on the appointment record and a new booking again asks for consent.
     */
    public function testConfirmedAppointmentStoresKvkkConsentAtAppointmentLevelAndRequiresConsentOnNewBooking(): void
    {
        $phone = '905556667788';
        $patient = Patient::factory()->create([
            'primary_phone' => $phone,
            'kvkk_consent_accepted' => false,
        ]);
        $doctor = Doctor::first();

        // Create a holding appointment directly (status = holding)
        $holdingAppt = Appointment::create([
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'appointment_date' => Carbon::today()->addDays(1)->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '10:30:00',
            'status' => 'holding',
        ]);

        // Initialise session in confirmation state, mimicking flow after KVKK acceptance.
        $session = WhatsappSession::firstOrCreate(
            ['phone_number' => $phone],
            ['current_state' => 'MAIN_MENU', 'state_payload' => []]
        );
        $session->update([
            'current_state' => 'CONFIRMING_BOOKING',
            'state_payload' => [
                'patient_id' => $patient->id,
                'doctor_id' => $doctor->id,
                'selected_date' => $holdingAppt->appointment_date,
                'appointment_id' => $holdingAppt->id,
                'slot_display' => $holdingAppt->start_time . ' - ' . $holdingAppt->end_time,
                'kvkk_accepted' => true,
            ],
        ]);

        $flow = $this->makeFlowService();
        // Simulate user confirming the booking.
        $flow->handle($phone, 'booking_yes');

        // Refresh appointment and assert KVKK consent persisted on appointment.
        $holdingAppt->refresh();
        $this->assertTrue($holdingAppt->kvkk_consent_accepted);
        $this->assertNotNull($holdingAppt->kvkk_consent_date);

        // Start a second booking for the same patient.
        $flow->handle($phone, 'main_new_appointment');
        // Choose existing patient (latest)
        $flow->handle($phone, 'patient_latest');
        // Session should now be awaiting KVKK consent again.
        $session->refresh();
        $this->assertEquals('AWAITING_KVKK_CONSENT', $session->current_state);
    }
}

    /** 6. Doctor selection and separate cancel button work in Arabic */
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
        $this->assertStringContainsString('يرجى اختيار الطبيب:', $listMsg['body']);
        $this->assertEquals('اختيار الطبيب', $listMsg['buttonText']);

        $listRowIds = array_column($listMsg['sections'][0]['rows'], 'id');
        $this->assertNotContains('flow_cancel', $listRowIds);

        $this->assertEquals('button', $btnMsg['type']);
        $cancelBtn = $btnMsg['buttons'][0]['reply'] ?? $btnMsg['buttons'][0];
        $this->assertEquals('flow_cancel', $cancelBtn['id']);
        $this->assertEquals('إلغاء والعودة', $cancelBtn['title']);

        // Cancel test
        $flow->handle($phone, 'flow_cancel');
        $session->refresh();
        $this->assertEquals('MAIN_MENU', $session->current_state);
        /**
     * Focused test: Confirm KVKK consent is persisted on the appointment record and a new booking again asks for consent.
     */
    public function testConfirmedAppointmentStoresKvkkConsentAtAppointmentLevelAndRequiresConsentOnNewBooking(): void
    {
        $phone = '905556667788';
        $patient = Patient::factory()->create([
            'primary_phone' => $phone,
            'kvkk_consent_accepted' => false,
        ]);
        $doctor = Doctor::first();

        // Create a holding appointment directly (status = holding)
        $holdingAppt = Appointment::create([
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'appointment_date' => Carbon::today()->addDays(1)->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '10:30:00',
            'status' => 'holding',
        ]);

        // Initialise session in confirmation state, mimicking flow after KVKK acceptance.
        $session = WhatsappSession::firstOrCreate(
            ['phone_number' => $phone],
            ['current_state' => 'MAIN_MENU', 'state_payload' => []]
        );
        $session->update([
            'current_state' => 'CONFIRMING_BOOKING',
            'state_payload' => [
                'patient_id' => $patient->id,
                'doctor_id' => $doctor->id,
                'selected_date' => $holdingAppt->appointment_date,
                'appointment_id' => $holdingAppt->id,
                'slot_display' => $holdingAppt->start_time . ' - ' . $holdingAppt->end_time,
                'kvkk_accepted' => true,
            ],
        ]);

        $flow = $this->makeFlowService();
        // Simulate user confirming the booking.
        $flow->handle($phone, 'booking_yes');

        // Refresh appointment and assert KVKK consent persisted on appointment.
        $holdingAppt->refresh();
        $this->assertTrue($holdingAppt->kvkk_consent_accepted);
        $this->assertNotNull($holdingAppt->kvkk_consent_date);

        // Start a second booking for the same patient.
        $flow->handle($phone, 'main_new_appointment');
        // Choose existing patient (latest)
        $flow->handle($phone, 'patient_latest');
        // Session should now be awaiting KVKK consent again.
        $session->refresh();
        $this->assertEquals('AWAITING_KVKK_CONSENT', $session->current_state);
    }
}

    /** 7. Date selection and cancellation work in Arabic */
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
        $this->assertStringContainsString('يرجى اختيار تاريخ الموعد المناسب:', $listMsg['body']);
        $this->assertEquals('اختيار التاريخ', $listMsg['buttonText']);

        $this->assertEquals('button', $btnMsg['type']);
        $cancelBtn = $btnMsg['buttons'][0]['reply'] ?? $btnMsg['buttons'][0];
        $this->assertEquals('flow_cancel', $cancelBtn['id']);
        $this->assertEquals('إلغاء والعودة', $cancelBtn['title']);

        $flow->handle($phone, 'flow_cancel');
        $session->refresh();
        $this->assertEquals('MAIN_MENU', $session->current_state);
        /**
     * Focused test: Confirm KVKK consent is persisted on the appointment record and a new booking again asks for consent.
     */
    public function testConfirmedAppointmentStoresKvkkConsentAtAppointmentLevelAndRequiresConsentOnNewBooking(): void
    {
        $phone = '905556667788';
        $patient = Patient::factory()->create([
            'primary_phone' => $phone,
            'kvkk_consent_accepted' => false,
        ]);
        $doctor = Doctor::first();

        // Create a holding appointment directly (status = holding)
        $holdingAppt = Appointment::create([
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'appointment_date' => Carbon::today()->addDays(1)->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '10:30:00',
            'status' => 'holding',
        ]);

        // Initialise session in confirmation state, mimicking flow after KVKK acceptance.
        $session = WhatsappSession::firstOrCreate(
            ['phone_number' => $phone],
            ['current_state' => 'MAIN_MENU', 'state_payload' => []]
        );
        $session->update([
            'current_state' => 'CONFIRMING_BOOKING',
            'state_payload' => [
                'patient_id' => $patient->id,
                'doctor_id' => $doctor->id,
                'selected_date' => $holdingAppt->appointment_date,
                'appointment_id' => $holdingAppt->id,
                'slot_display' => $holdingAppt->start_time . ' - ' . $holdingAppt->end_time,
                'kvkk_accepted' => true,
            ],
        ]);

        $flow = $this->makeFlowService();
        // Simulate user confirming the booking.
        $flow->handle($phone, 'booking_yes');

        // Refresh appointment and assert KVKK consent persisted on appointment.
        $holdingAppt->refresh();
        $this->assertTrue($holdingAppt->kvkk_consent_accepted);
        $this->assertNotNull($holdingAppt->kvkk_consent_date);

        // Start a second booking for the same patient.
        $flow->handle($phone, 'main_new_appointment');
        // Choose existing patient (latest)
        $flow->handle($phone, 'patient_latest');
        // Session should now be awaiting KVKK consent again.
        $session->refresh();
        $this->assertEquals('AWAITING_KVKK_CONSENT', $session->current_state);
    }
}

    /** 8. Time selection and cancellation work in Arabic */
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
                'available_dates' => [['date' => '2026-11-08', 'day_name' => 'الأحد', 'formatted' => '2026-11-08']],
            ],
        ]);

        $flow->handle($phone, 'date_2026-11-08');

        $count = count($this->sentMessages);
        $listMsg = $this->sentMessages[$count - 2];
        $btnMsg = $this->sentMessages[$count - 1];

        $this->assertEquals('list', $listMsg['type']);
        $this->assertStringContainsString('يرجى اختيار وقت الموعد المتاح:', $listMsg['body']);
        $this->assertEquals('اختيار الوقت', $listMsg['buttonText']);

        $this->assertEquals('button', $btnMsg['type']);
        $cancelBtn = $btnMsg['buttons'][0]['reply'] ?? $btnMsg['buttons'][0];
        $this->assertEquals('flow_cancel', $cancelBtn['id']);
        $this->assertEquals('إلغاء والعودة', $cancelBtn['title']);

        $flow->handle($phone, 'flow_cancel');
        $session->refresh();
        $this->assertEquals('MAIN_MENU', $session->current_state);
        /**
     * Focused test: Confirm KVKK consent is persisted on the appointment record and a new booking again asks for consent.
     */
    public function testConfirmedAppointmentStoresKvkkConsentAtAppointmentLevelAndRequiresConsentOnNewBooking(): void
    {
        $phone = '905556667788';
        $patient = Patient::factory()->create([
            'primary_phone' => $phone,
            'kvkk_consent_accepted' => false,
        ]);
        $doctor = Doctor::first();

        // Create a holding appointment directly (status = holding)
        $holdingAppt = Appointment::create([
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'appointment_date' => Carbon::today()->addDays(1)->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '10:30:00',
            'status' => 'holding',
        ]);

        // Initialise session in confirmation state, mimicking flow after KVKK acceptance.
        $session = WhatsappSession::firstOrCreate(
            ['phone_number' => $phone],
            ['current_state' => 'MAIN_MENU', 'state_payload' => []]
        );
        $session->update([
            'current_state' => 'CONFIRMING_BOOKING',
            'state_payload' => [
                'patient_id' => $patient->id,
                'doctor_id' => $doctor->id,
                'selected_date' => $holdingAppt->appointment_date,
                'appointment_id' => $holdingAppt->id,
                'slot_display' => $holdingAppt->start_time . ' - ' . $holdingAppt->end_time,
                'kvkk_accepted' => true,
            ],
        ]);

        $flow = $this->makeFlowService();
        // Simulate user confirming the booking.
        $flow->handle($phone, 'booking_yes');

        // Refresh appointment and assert KVKK consent persisted on appointment.
        $holdingAppt->refresh();
        $this->assertTrue($holdingAppt->kvkk_consent_accepted);
        $this->assertNotNull($holdingAppt->kvkk_consent_date);

        // Start a second booking for the same patient.
        $flow->handle($phone, 'main_new_appointment');
        // Choose existing patient (latest)
        $flow->handle($phone, 'patient_latest');
        // Session should now be awaiting KVKK consent again.
        $session->refresh();
        $this->assertEquals('AWAITING_KVKK_CONSENT', $session->current_state);
    }
}

    /** 9. Selecting a slot creates one temporary reservation in Arabic */
    public function testSelectingSlotCreatesOneTemporaryReservation(): void
    {
        $phone = '905558889900';
        $patient = Patient::factory()->create(['primary_phone' => $phone, 'full_name' => 'محمد السعيد']);
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

        // Verify review confirmation message format in Arabic
        $last = end($this->sentMessages);
        $this->assertEquals('button', $last['type']);
        $this->assertStringContainsString('تفاصيل الموعد', $last['body']);
        $this->assertStringContainsString('المريض: محمد السعيد', $last['body']);
        $this->assertStringContainsString('هل ترغب في تأكيد الحجز؟', $last['body']);

        $buttons = $last['buttons'];
        $this->assertCount(2, $buttons);

        $btn1 = $buttons[0]['reply'] ?? $buttons[0];
        $btn2 = $buttons[1]['reply'] ?? $buttons[1];

        $this->assertEquals('booking_yes', $btn1['id']);
        $this->assertEquals('نعم', $btn1['title']);
        $this->assertEquals('booking_no', $btn2['id']);
        $this->assertEquals('لا', $btn2['title']);
        /**
     * Focused test: Confirm KVKK consent is persisted on the appointment record and a new booking again asks for consent.
     */
    public function testConfirmedAppointmentStoresKvkkConsentAtAppointmentLevelAndRequiresConsentOnNewBooking(): void
    {
        $phone = '905556667788';
        $patient = Patient::factory()->create([
            'primary_phone' => $phone,
            'kvkk_consent_accepted' => false,
        ]);
        $doctor = Doctor::first();

        // Create a holding appointment directly (status = holding)
        $holdingAppt = Appointment::create([
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'appointment_date' => Carbon::today()->addDays(1)->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '10:30:00',
            'status' => 'holding',
        ]);

        // Initialise session in confirmation state, mimicking flow after KVKK acceptance.
        $session = WhatsappSession::firstOrCreate(
            ['phone_number' => $phone],
            ['current_state' => 'MAIN_MENU', 'state_payload' => []]
        );
        $session->update([
            'current_state' => 'CONFIRMING_BOOKING',
            'state_payload' => [
                'patient_id' => $patient->id,
                'doctor_id' => $doctor->id,
                'selected_date' => $holdingAppt->appointment_date,
                'appointment_id' => $holdingAppt->id,
                'slot_display' => $holdingAppt->start_time . ' - ' . $holdingAppt->end_time,
                'kvkk_accepted' => true,
            ],
        ]);

        $flow = $this->makeFlowService();
        // Simulate user confirming the booking.
        $flow->handle($phone, 'booking_yes');

        // Refresh appointment and assert KVKK consent persisted on appointment.
        $holdingAppt->refresh();
        $this->assertTrue($holdingAppt->kvkk_consent_accepted);
        $this->assertNotNull($holdingAppt->kvkk_consent_date);

        // Start a second booking for the same patient.
        $flow->handle($phone, 'main_new_appointment');
        // Choose existing patient (latest)
        $flow->handle($phone, 'patient_latest');
        // Session should now be awaiting KVKK consent again.
        $session->refresh();
        $this->assertEquals('AWAITING_KVKK_CONSENT', $session->current_state);
    }
}

    /** 10. Pressing Yes confirms the exact pending appointment in Arabic */
    public function testPressingYesConfirmsTheExactPendingAppointment(): void
    {
        $phone = '905559990011';
        $patient = Patient::factory()->create(['primary_phone' => $phone, 'full_name' => 'محمد']);
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

        // Verify clean confirmation message in Arabic
        $count = count($this->sentMessages);
        $confMsg = $this->sentMessages[$count - 2];
        $mainMenuMsg = $this->sentMessages[$count - 1];

        $this->assertEquals('text', $confMsg['type']);
        $this->assertStringContainsString('تم تأكيد موعدك بنجاح.', $confMsg['msg']);
        $this->assertStringContainsString('المريض: محمد', $confMsg['msg']);
        $this->assertStringContainsString('التاريخ: 2026-11-08', $confMsg['msg']);
        $this->assertStringContainsString('الوقت: 09:00 - 09:30', $confMsg['msg']);
        $this->assertStringContainsString('شكراً لاختياركم عيادة الشفاء الطبية.', $confMsg['msg']);

        // Main menu automatically displayed
        $this->assertEquals('button', $mainMenuMsg['type']);
        $this->assertStringContainsString('مرحباً بكم في عيادة الشفاء الطبية.', $mainMenuMsg['body']);

        $session->refresh();
        $this->assertEquals('MAIN_MENU', $session->current_state);
        $this->assertEmpty($session->state_payload);
        /**
     * Focused test: Confirm KVKK consent is persisted on the appointment record and a new booking again asks for consent.
     */
    public function testConfirmedAppointmentStoresKvkkConsentAtAppointmentLevelAndRequiresConsentOnNewBooking(): void
    {
        $phone = '905556667788';
        $patient = Patient::factory()->create([
            'primary_phone' => $phone,
            'kvkk_consent_accepted' => false,
        ]);
        $doctor = Doctor::first();

        // Create a holding appointment directly (status = holding)
        $holdingAppt = Appointment::create([
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'appointment_date' => Carbon::today()->addDays(1)->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '10:30:00',
            'status' => 'holding',
        ]);

        // Initialise session in confirmation state, mimicking flow after KVKK acceptance.
        $session = WhatsappSession::firstOrCreate(
            ['phone_number' => $phone],
            ['current_state' => 'MAIN_MENU', 'state_payload' => []]
        );
        $session->update([
            'current_state' => 'CONFIRMING_BOOKING',
            'state_payload' => [
                'patient_id' => $patient->id,
                'doctor_id' => $doctor->id,
                'selected_date' => $holdingAppt->appointment_date,
                'appointment_id' => $holdingAppt->id,
                'slot_display' => $holdingAppt->start_time . ' - ' . $holdingAppt->end_time,
                'kvkk_accepted' => true,
            ],
        ]);

        $flow = $this->makeFlowService();
        // Simulate user confirming the booking.
        $flow->handle($phone, 'booking_yes');

        // Refresh appointment and assert KVKK consent persisted on appointment.
        $holdingAppt->refresh();
        $this->assertTrue($holdingAppt->kvkk_consent_accepted);
        $this->assertNotNull($holdingAppt->kvkk_consent_date);

        // Start a second booking for the same patient.
        $flow->handle($phone, 'main_new_appointment');
        // Choose existing patient (latest)
        $flow->handle($phone, 'patient_latest');
        // Session should now be awaiting KVKK consent again.
        $session->refresh();
        $this->assertEquals('AWAITING_KVKK_CONSENT', $session->current_state);
    }
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
        /**
     * Focused test: Confirm KVKK consent is persisted on the appointment record and a new booking again asks for consent.
     */
    public function testConfirmedAppointmentStoresKvkkConsentAtAppointmentLevelAndRequiresConsentOnNewBooking(): void
    {
        $phone = '905556667788';
        $patient = Patient::factory()->create([
            'primary_phone' => $phone,
            'kvkk_consent_accepted' => false,
        ]);
        $doctor = Doctor::first();

        // Create a holding appointment directly (status = holding)
        $holdingAppt = Appointment::create([
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'appointment_date' => Carbon::today()->addDays(1)->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '10:30:00',
            'status' => 'holding',
        ]);

        // Initialise session in confirmation state, mimicking flow after KVKK acceptance.
        $session = WhatsappSession::firstOrCreate(
            ['phone_number' => $phone],
            ['current_state' => 'MAIN_MENU', 'state_payload' => []]
        );
        $session->update([
            'current_state' => 'CONFIRMING_BOOKING',
            'state_payload' => [
                'patient_id' => $patient->id,
                'doctor_id' => $doctor->id,
                'selected_date' => $holdingAppt->appointment_date,
                'appointment_id' => $holdingAppt->id,
                'slot_display' => $holdingAppt->start_time . ' - ' . $holdingAppt->end_time,
                'kvkk_accepted' => true,
            ],
        ]);

        $flow = $this->makeFlowService();
        // Simulate user confirming the booking.
        $flow->handle($phone, 'booking_yes');

        // Refresh appointment and assert KVKK consent persisted on appointment.
        $holdingAppt->refresh();
        $this->assertTrue($holdingAppt->kvkk_consent_accepted);
        $this->assertNotNull($holdingAppt->kvkk_consent_date);

        // Start a second booking for the same patient.
        $flow->handle($phone, 'main_new_appointment');
        // Choose existing patient (latest)
        $flow->handle($phone, 'patient_latest');
        // Session should now be awaiting KVKK consent again.
        $session->refresh();
        $this->assertEquals('AWAITING_KVKK_CONSENT', $session->current_state);
    }
}

    /** 12. Confirmation does not return to time selection */
    public function testConfirmationDoesNotReturnToTimeSelection(): void
    {
        $phone = '905551113344';
        $patient = Patient::factory()->create(['primary_phone' => $phone, 'full_name' => 'أحمد']);
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
                $this->assertStringNotContainsString('يرجى اختيار وقت الموعد المتاح:', $msg['body']);
                /**
     * Focused test: Confirm KVKK consent is persisted on the appointment record and a new booking again asks for consent.
     */
    public function testConfirmedAppointmentStoresKvkkConsentAtAppointmentLevelAndRequiresConsentOnNewBooking(): void
    {
        $phone = '905556667788';
        $patient = Patient::factory()->create([
            'primary_phone' => $phone,
            'kvkk_consent_accepted' => false,
        ]);
        $doctor = Doctor::first();

        // Create a holding appointment directly (status = holding)
        $holdingAppt = Appointment::create([
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'appointment_date' => Carbon::today()->addDays(1)->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '10:30:00',
            'status' => 'holding',
        ]);

        // Initialise session in confirmation state, mimicking flow after KVKK acceptance.
        $session = WhatsappSession::firstOrCreate(
            ['phone_number' => $phone],
            ['current_state' => 'MAIN_MENU', 'state_payload' => []]
        );
        $session->update([
            'current_state' => 'CONFIRMING_BOOKING',
            'state_payload' => [
                'patient_id' => $patient->id,
                'doctor_id' => $doctor->id,
                'selected_date' => $holdingAppt->appointment_date,
                'appointment_id' => $holdingAppt->id,
                'slot_display' => $holdingAppt->start_time . ' - ' . $holdingAppt->end_time,
                'kvkk_accepted' => true,
            ],
        ]);

        $flow = $this->makeFlowService();
        // Simulate user confirming the booking.
        $flow->handle($phone, 'booking_yes');

        // Refresh appointment and assert KVKK consent persisted on appointment.
        $holdingAppt->refresh();
        $this->assertTrue($holdingAppt->kvkk_consent_accepted);
        $this->assertNotNull($holdingAppt->kvkk_consent_date);

        // Start a second booking for the same patient.
        $flow->handle($phone, 'main_new_appointment');
        // Choose existing patient (latest)
        $flow->handle($phone, 'patient_latest');
        // Session should now be awaiting KVKK consent again.
        $session->refresh();
        $this->assertEquals('AWAITING_KVKK_CONSENT', $session->current_state);
    }
}
            /**
     * Focused test: Confirm KVKK consent is persisted on the appointment record and a new booking again asks for consent.
     */
    public function testConfirmedAppointmentStoresKvkkConsentAtAppointmentLevelAndRequiresConsentOnNewBooking(): void
    {
        $phone = '905556667788';
        $patient = Patient::factory()->create([
            'primary_phone' => $phone,
            'kvkk_consent_accepted' => false,
        ]);
        $doctor = Doctor::first();

        // Create a holding appointment directly (status = holding)
        $holdingAppt = Appointment::create([
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'appointment_date' => Carbon::today()->addDays(1)->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '10:30:00',
            'status' => 'holding',
        ]);

        // Initialise session in confirmation state, mimicking flow after KVKK acceptance.
        $session = WhatsappSession::firstOrCreate(
            ['phone_number' => $phone],
            ['current_state' => 'MAIN_MENU', 'state_payload' => []]
        );
        $session->update([
            'current_state' => 'CONFIRMING_BOOKING',
            'state_payload' => [
                'patient_id' => $patient->id,
                'doctor_id' => $doctor->id,
                'selected_date' => $holdingAppt->appointment_date,
                'appointment_id' => $holdingAppt->id,
                'slot_display' => $holdingAppt->start_time . ' - ' . $holdingAppt->end_time,
                'kvkk_accepted' => true,
            ],
        ]);

        $flow = $this->makeFlowService();
        // Simulate user confirming the booking.
        $flow->handle($phone, 'booking_yes');

        // Refresh appointment and assert KVKK consent persisted on appointment.
        $holdingAppt->refresh();
        $this->assertTrue($holdingAppt->kvkk_consent_accepted);
        $this->assertNotNull($holdingAppt->kvkk_consent_date);

        // Start a second booking for the same patient.
        $flow->handle($phone, 'main_new_appointment');
        // Choose existing patient (latest)
        $flow->handle($phone, 'patient_latest');
        // Session should now be awaiting KVKK consent again.
        $session->refresh();
        $this->assertEquals('AWAITING_KVKK_CONSENT', $session->current_state);
    }
}

        $session->refresh();
        $this->assertEquals('MAIN_MENU', $session->current_state);
        /**
     * Focused test: Confirm KVKK consent is persisted on the appointment record and a new booking again asks for consent.
     */
    public function testConfirmedAppointmentStoresKvkkConsentAtAppointmentLevelAndRequiresConsentOnNewBooking(): void
    {
        $phone = '905556667788';
        $patient = Patient::factory()->create([
            'primary_phone' => $phone,
            'kvkk_consent_accepted' => false,
        ]);
        $doctor = Doctor::first();

        // Create a holding appointment directly (status = holding)
        $holdingAppt = Appointment::create([
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'appointment_date' => Carbon::today()->addDays(1)->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '10:30:00',
            'status' => 'holding',
        ]);

        // Initialise session in confirmation state, mimicking flow after KVKK acceptance.
        $session = WhatsappSession::firstOrCreate(
            ['phone_number' => $phone],
            ['current_state' => 'MAIN_MENU', 'state_payload' => []]
        );
        $session->update([
            'current_state' => 'CONFIRMING_BOOKING',
            'state_payload' => [
                'patient_id' => $patient->id,
                'doctor_id' => $doctor->id,
                'selected_date' => $holdingAppt->appointment_date,
                'appointment_id' => $holdingAppt->id,
                'slot_display' => $holdingAppt->start_time . ' - ' . $holdingAppt->end_time,
                'kvkk_accepted' => true,
            ],
        ]);

        $flow = $this->makeFlowService();
        // Simulate user confirming the booking.
        $flow->handle($phone, 'booking_yes');

        // Refresh appointment and assert KVKK consent persisted on appointment.
        $holdingAppt->refresh();
        $this->assertTrue($holdingAppt->kvkk_consent_accepted);
        $this->assertNotNull($holdingAppt->kvkk_consent_date);

        // Start a second booking for the same patient.
        $flow->handle($phone, 'main_new_appointment');
        // Choose existing patient (latest)
        $flow->handle($phone, 'patient_latest');
        // Session should now be awaiting KVKK consent again.
        $session->refresh();
        $this->assertEquals('AWAITING_KVKK_CONSENT', $session->current_state);
    }
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
        /**
     * Focused test: Confirm KVKK consent is persisted on the appointment record and a new booking again asks for consent.
     */
    public function testConfirmedAppointmentStoresKvkkConsentAtAppointmentLevelAndRequiresConsentOnNewBooking(): void
    {
        $phone = '905556667788';
        $patient = Patient::factory()->create([
            'primary_phone' => $phone,
            'kvkk_consent_accepted' => false,
        ]);
        $doctor = Doctor::first();

        // Create a holding appointment directly (status = holding)
        $holdingAppt = Appointment::create([
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'appointment_date' => Carbon::today()->addDays(1)->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '10:30:00',
            'status' => 'holding',
        ]);

        // Initialise session in confirmation state, mimicking flow after KVKK acceptance.
        $session = WhatsappSession::firstOrCreate(
            ['phone_number' => $phone],
            ['current_state' => 'MAIN_MENU', 'state_payload' => []]
        );
        $session->update([
            'current_state' => 'CONFIRMING_BOOKING',
            'state_payload' => [
                'patient_id' => $patient->id,
                'doctor_id' => $doctor->id,
                'selected_date' => $holdingAppt->appointment_date,
                'appointment_id' => $holdingAppt->id,
                'slot_display' => $holdingAppt->start_time . ' - ' . $holdingAppt->end_time,
                'kvkk_accepted' => true,
            ],
        ]);

        $flow = $this->makeFlowService();
        // Simulate user confirming the booking.
        $flow->handle($phone, 'booking_yes');

        // Refresh appointment and assert KVKK consent persisted on appointment.
        $holdingAppt->refresh();
        $this->assertTrue($holdingAppt->kvkk_consent_accepted);
        $this->assertNotNull($holdingAppt->kvkk_consent_date);

        // Start a second booking for the same patient.
        $flow->handle($phone, 'main_new_appointment');
        // Choose existing patient (latest)
        $flow->handle($phone, 'patient_latest');
        // Session should now be awaiting KVKK consent again.
        $session->refresh();
        $this->assertEquals('AWAITING_KVKK_CONSENT', $session->current_state);
    }
}

    /** 14. Explicit consent for medical data storage outside Turkey required for every booking */
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

        // Bot must NOT bypass consent and must explicitly mention medical data and storage outside Turkey
        $last = end($this->sentMessages);
        $this->assertEquals('button', $last['type']);
        $this->assertStringContainsString('الطبية', $last['body']);
        $this->assertStringContainsString('خارج تركيا', $last['body']);

        $btn1 = $last['buttons'][0]['reply'] ?? $last['buttons'][0];
        $btn2 = $last['buttons'][1]['reply'] ?? $last['buttons'][1];
        $this->assertEquals('kvkk_yes', $btn1['id']);
        $this->assertEquals('موافق', $btn1['title']);
        $this->assertEquals('kvkk_no', $btn2['id']);
        $this->assertEquals('لا أوافق', $btn2['title']);

        $session = WhatsappSession::where('phone_number', $phone)->first();
        $this->assertEquals('AWAITING_KVKK_CONSENT', $session->current_state);
        /**
     * Focused test: Confirm KVKK consent is persisted on the appointment record and a new booking again asks for consent.
     */
    public function testConfirmedAppointmentStoresKvkkConsentAtAppointmentLevelAndRequiresConsentOnNewBooking(): void
    {
        $phone = '905556667788';
        $patient = Patient::factory()->create([
            'primary_phone' => $phone,
            'kvkk_consent_accepted' => false,
        ]);
        $doctor = Doctor::first();

        // Create a holding appointment directly (status = holding)
        $holdingAppt = Appointment::create([
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'appointment_date' => Carbon::today()->addDays(1)->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '10:30:00',
            'status' => 'holding',
        ]);

        // Initialise session in confirmation state, mimicking flow after KVKK acceptance.
        $session = WhatsappSession::firstOrCreate(
            ['phone_number' => $phone],
            ['current_state' => 'MAIN_MENU', 'state_payload' => []]
        );
        $session->update([
            'current_state' => 'CONFIRMING_BOOKING',
            'state_payload' => [
                'patient_id' => $patient->id,
                'doctor_id' => $doctor->id,
                'selected_date' => $holdingAppt->appointment_date,
                'appointment_id' => $holdingAppt->id,
                'slot_display' => $holdingAppt->start_time . ' - ' . $holdingAppt->end_time,
                'kvkk_accepted' => true,
            ],
        ]);

        $flow = $this->makeFlowService();
        // Simulate user confirming the booking.
        $flow->handle($phone, 'booking_yes');

        // Refresh appointment and assert KVKK consent persisted on appointment.
        $holdingAppt->refresh();
        $this->assertTrue($holdingAppt->kvkk_consent_accepted);
        $this->assertNotNull($holdingAppt->kvkk_consent_date);

        // Start a second booking for the same patient.
        $flow->handle($phone, 'main_new_appointment');
        // Choose existing patient (latest)
        $flow->handle($phone, 'patient_latest');
        // Session should now be awaiting KVKK consent again.
        $session->refresh();
        $this->assertEquals('AWAITING_KVKK_CONSENT', $session->current_state);
    }
}

    /** Focused test 1: Explicit consent refusal releases slot hold and returns to main menu */
    public function testExplicitConsentRefusalReleasesHoldAndReturnsToMainMenu(): void
    {
        $phone = '905558881122';
        $patient = Patient::factory()->create(['primary_phone' => $phone]);
        $doctor = Doctor::first();

        $holdingAppt = Appointment::create([
            'reference_code' => 'AP-CONSENT-REFUSE',
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
            'current_state' => 'AWAITING_KVKK_CONSENT',
            'state_payload' => [
                'patient_id' => $patient->id,
                'appointment_id' => $holdingAppt->id,
            ],
        ]);

        // Refuse consent
        $flow->handle($phone, 'kvkk_no');

        // Hold appointment must be cancelled
        $holdingAppt->refresh();
        $this->assertEquals('cancelled_by_patient', $holdingAppt->status);
        $this->assertStringContainsString('خارج تركيا', $holdingAppt->cancellation_reason);

        // Session reset to main menu
        $session->refresh();
        $this->assertEquals('MAIN_MENU', $session->current_state);
        $this->assertEmpty($session->state_payload);

        // Refusal message sent in Arabic
        $last = $this->sentMessages[count($this->sentMessages) - 2];
        $this->assertEquals('text', $last['type']);
        $this->assertStringContainsString('تم إلغاء عملية الحجز نظراً لعدم الموافقة على تخزين ومعالجة البيانات الطبية خارج تركيا.', $last['msg']);
        /**
     * Focused test: Confirm KVKK consent is persisted on the appointment record and a new booking again asks for consent.
     */
    public function testConfirmedAppointmentStoresKvkkConsentAtAppointmentLevelAndRequiresConsentOnNewBooking(): void
    {
        $phone = '905556667788';
        $patient = Patient::factory()->create([
            'primary_phone' => $phone,
            'kvkk_consent_accepted' => false,
        ]);
        $doctor = Doctor::first();

        // Create a holding appointment directly (status = holding)
        $holdingAppt = Appointment::create([
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'appointment_date' => Carbon::today()->addDays(1)->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '10:30:00',
            'status' => 'holding',
        ]);

        // Initialise session in confirmation state, mimicking flow after KVKK acceptance.
        $session = WhatsappSession::firstOrCreate(
            ['phone_number' => $phone],
            ['current_state' => 'MAIN_MENU', 'state_payload' => []]
        );
        $session->update([
            'current_state' => 'CONFIRMING_BOOKING',
            'state_payload' => [
                'patient_id' => $patient->id,
                'doctor_id' => $doctor->id,
                'selected_date' => $holdingAppt->appointment_date,
                'appointment_id' => $holdingAppt->id,
                'slot_display' => $holdingAppt->start_time . ' - ' . $holdingAppt->end_time,
                'kvkk_accepted' => true,
            ],
        ]);

        $flow = $this->makeFlowService();
        // Simulate user confirming the booking.
        $flow->handle($phone, 'booking_yes');

        // Refresh appointment and assert KVKK consent persisted on appointment.
        $holdingAppt->refresh();
        $this->assertTrue($holdingAppt->kvkk_consent_accepted);
        $this->assertNotNull($holdingAppt->kvkk_consent_date);

        // Start a second booking for the same patient.
        $flow->handle($phone, 'main_new_appointment');
        // Choose existing patient (latest)
        $flow->handle($phone, 'patient_latest');
        // Session should now be awaiting KVKK consent again.
        $session->refresh();
        $this->assertEquals('AWAITING_KVKK_CONSENT', $session->current_state);
    }
}

    /** Focused test 2: Explicit consent acceptance advances flow and saves consent */
    public function testExplicitConsentAcceptanceAdvancesFlowAndSavesConsent(): void
    {
        $phone = '905558882233';
        $patient = Patient::factory()->create([
            'primary_phone' => $phone,
            'kvkk_consent_accepted' => false,
        ]);

        $flow = $this->makeFlowService();
        $session = WhatsappSession::firstOrCreate(
            ['phone_number' => $phone],
            ['current_state' => 'MAIN_MENU', 'state_payload' => []]
        );
        $session->update([
            'current_state' => 'AWAITING_KVKK_CONSENT',
            'state_payload' => ['patient_id' => $patient->id],
        ]);

        // Accept consent
        $flow->handle($phone, 'kvkk_yes');

        // Patient record updated
        $patient->refresh();
        $this->assertTrue($patient->kvkk_consent_accepted);
        $this->assertNotNull($patient->kvkk_consent_date);

        // Advanced to specialty selection
        $session->refresh();
        $this->assertEquals('SELECTING_SPECIALTY', $session->current_state);
        $this->assertTrue($session->state_payload['kvkk_accepted']);
        /**
     * Focused test: Confirm KVKK consent is persisted on the appointment record and a new booking again asks for consent.
     */
    public function testConfirmedAppointmentStoresKvkkConsentAtAppointmentLevelAndRequiresConsentOnNewBooking(): void
    {
        $phone = '905556667788';
        $patient = Patient::factory()->create([
            'primary_phone' => $phone,
            'kvkk_consent_accepted' => false,
        ]);
        $doctor = Doctor::first();

        // Create a holding appointment directly (status = holding)
        $holdingAppt = Appointment::create([
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'appointment_date' => Carbon::today()->addDays(1)->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '10:30:00',
            'status' => 'holding',
        ]);

        // Initialise session in confirmation state, mimicking flow after KVKK acceptance.
        $session = WhatsappSession::firstOrCreate(
            ['phone_number' => $phone],
            ['current_state' => 'MAIN_MENU', 'state_payload' => []]
        );
        $session->update([
            'current_state' => 'CONFIRMING_BOOKING',
            'state_payload' => [
                'patient_id' => $patient->id,
                'doctor_id' => $doctor->id,
                'selected_date' => $holdingAppt->appointment_date,
                'appointment_id' => $holdingAppt->id,
                'slot_display' => $holdingAppt->start_time . ' - ' . $holdingAppt->end_time,
                'kvkk_accepted' => true,
            ],
        ]);

        $flow = $this->makeFlowService();
        // Simulate user confirming the booking.
        $flow->handle($phone, 'booking_yes');

        // Refresh appointment and assert KVKK consent persisted on appointment.
        $holdingAppt->refresh();
        $this->assertTrue($holdingAppt->kvkk_consent_accepted);
        $this->assertNotNull($holdingAppt->kvkk_consent_date);

        // Start a second booking for the same patient.
        $flow->handle($phone, 'main_new_appointment');
        // Choose existing patient (latest)
        $flow->handle($phone, 'patient_latest');
        // Session should now be awaiting KVKK consent again.
        $session->refresh();
        $this->assertEquals('AWAITING_KVKK_CONSENT', $session->current_state);
    }
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
        /**
     * Focused test: Confirm KVKK consent is persisted on the appointment record and a new booking again asks for consent.
     */
    public function testConfirmedAppointmentStoresKvkkConsentAtAppointmentLevelAndRequiresConsentOnNewBooking(): void
    {
        $phone = '905556667788';
        $patient = Patient::factory()->create([
            'primary_phone' => $phone,
            'kvkk_consent_accepted' => false,
        ]);
        $doctor = Doctor::first();

        // Create a holding appointment directly (status = holding)
        $holdingAppt = Appointment::create([
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'appointment_date' => Carbon::today()->addDays(1)->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '10:30:00',
            'status' => 'holding',
        ]);

        // Initialise session in confirmation state, mimicking flow after KVKK acceptance.
        $session = WhatsappSession::firstOrCreate(
            ['phone_number' => $phone],
            ['current_state' => 'MAIN_MENU', 'state_payload' => []]
        );
        $session->update([
            'current_state' => 'CONFIRMING_BOOKING',
            'state_payload' => [
                'patient_id' => $patient->id,
                'doctor_id' => $doctor->id,
                'selected_date' => $holdingAppt->appointment_date,
                'appointment_id' => $holdingAppt->id,
                'slot_display' => $holdingAppt->start_time . ' - ' . $holdingAppt->end_time,
                'kvkk_accepted' => true,
            ],
        ]);

        $flow = $this->makeFlowService();
        // Simulate user confirming the booking.
        $flow->handle($phone, 'booking_yes');

        // Refresh appointment and assert KVKK consent persisted on appointment.
        $holdingAppt->refresh();
        $this->assertTrue($holdingAppt->kvkk_consent_accepted);
        $this->assertNotNull($holdingAppt->kvkk_consent_date);

        // Start a second booking for the same patient.
        $flow->handle($phone, 'main_new_appointment');
        // Choose existing patient (latest)
        $flow->handle($phone, 'patient_latest');
        // Session should now be awaiting KVKK consent again.
        $session->refresh();
        $this->assertEquals('AWAITING_KVKK_CONSENT', $session->current_state);
    }
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
                'available_dates' => [['date' => '2026-11-08', 'day_name' => 'الأحد', 'formatted' => '2026-11-08']],
            ],
        ]);

        $flow->handle($phone, 'booking_yes');

        // Must not be confirmed
        $expiredAppt->refresh();
        $this->assertNotEquals('confirmed', $expiredAppt->status);
        $this->assertEquals('cancelled_by_clinic', $expiredAppt->status);

        // User informed of expiration in Arabic
        $hasExpirationMsg = false;
        foreach ($this->sentMessages as $m) {
            if (isset($m['msg']) && str_contains($m['msg'], 'انتهت صلاحية الحجز المؤقت')) {
                $hasExpirationMsg = true;
                break;
                /**
     * Focused test: Confirm KVKK consent is persisted on the appointment record and a new booking again asks for consent.
     */
    public function testConfirmedAppointmentStoresKvkkConsentAtAppointmentLevelAndRequiresConsentOnNewBooking(): void
    {
        $phone = '905556667788';
        $patient = Patient::factory()->create([
            'primary_phone' => $phone,
            'kvkk_consent_accepted' => false,
        ]);
        $doctor = Doctor::first();

        // Create a holding appointment directly (status = holding)
        $holdingAppt = Appointment::create([
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'appointment_date' => Carbon::today()->addDays(1)->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '10:30:00',
            'status' => 'holding',
        ]);

        // Initialise session in confirmation state, mimicking flow after KVKK acceptance.
        $session = WhatsappSession::firstOrCreate(
            ['phone_number' => $phone],
            ['current_state' => 'MAIN_MENU', 'state_payload' => []]
        );
        $session->update([
            'current_state' => 'CONFIRMING_BOOKING',
            'state_payload' => [
                'patient_id' => $patient->id,
                'doctor_id' => $doctor->id,
                'selected_date' => $holdingAppt->appointment_date,
                'appointment_id' => $holdingAppt->id,
                'slot_display' => $holdingAppt->start_time . ' - ' . $holdingAppt->end_time,
                'kvkk_accepted' => true,
            ],
        ]);

        $flow = $this->makeFlowService();
        // Simulate user confirming the booking.
        $flow->handle($phone, 'booking_yes');

        // Refresh appointment and assert KVKK consent persisted on appointment.
        $holdingAppt->refresh();
        $this->assertTrue($holdingAppt->kvkk_consent_accepted);
        $this->assertNotNull($holdingAppt->kvkk_consent_date);

        // Start a second booking for the same patient.
        $flow->handle($phone, 'main_new_appointment');
        // Choose existing patient (latest)
        $flow->handle($phone, 'patient_latest');
        // Session should now be awaiting KVKK consent again.
        $session->refresh();
        $this->assertEquals('AWAITING_KVKK_CONSENT', $session->current_state);
    }
}
            /**
     * Focused test: Confirm KVKK consent is persisted on the appointment record and a new booking again asks for consent.
     */
    public function testConfirmedAppointmentStoresKvkkConsentAtAppointmentLevelAndRequiresConsentOnNewBooking(): void
    {
        $phone = '905556667788';
        $patient = Patient::factory()->create([
            'primary_phone' => $phone,
            'kvkk_consent_accepted' => false,
        ]);
        $doctor = Doctor::first();

        // Create a holding appointment directly (status = holding)
        $holdingAppt = Appointment::create([
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'appointment_date' => Carbon::today()->addDays(1)->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '10:30:00',
            'status' => 'holding',
        ]);

        // Initialise session in confirmation state, mimicking flow after KVKK acceptance.
        $session = WhatsappSession::firstOrCreate(
            ['phone_number' => $phone],
            ['current_state' => 'MAIN_MENU', 'state_payload' => []]
        );
        $session->update([
            'current_state' => 'CONFIRMING_BOOKING',
            'state_payload' => [
                'patient_id' => $patient->id,
                'doctor_id' => $doctor->id,
                'selected_date' => $holdingAppt->appointment_date,
                'appointment_id' => $holdingAppt->id,
                'slot_display' => $holdingAppt->start_time . ' - ' . $holdingAppt->end_time,
                'kvkk_accepted' => true,
            ],
        ]);

        $flow = $this->makeFlowService();
        // Simulate user confirming the booking.
        $flow->handle($phone, 'booking_yes');

        // Refresh appointment and assert KVKK consent persisted on appointment.
        $holdingAppt->refresh();
        $this->assertTrue($holdingAppt->kvkk_consent_accepted);
        $this->assertNotNull($holdingAppt->kvkk_consent_date);

        // Start a second booking for the same patient.
        $flow->handle($phone, 'main_new_appointment');
        // Choose existing patient (latest)
        $flow->handle($phone, 'patient_latest');
        // Session should now be awaiting KVKK consent again.
        $session->refresh();
        $this->assertEquals('AWAITING_KVKK_CONSENT', $session->current_state);
    }
}
        $this->assertTrue($hasExpirationMsg);
        /**
     * Focused test: Confirm KVKK consent is persisted on the appointment record and a new booking again asks for consent.
     */
    public function testConfirmedAppointmentStoresKvkkConsentAtAppointmentLevelAndRequiresConsentOnNewBooking(): void
    {
        $phone = '905556667788';
        $patient = Patient::factory()->create([
            'primary_phone' => $phone,
            'kvkk_consent_accepted' => false,
        ]);
        $doctor = Doctor::first();

        // Create a holding appointment directly (status = holding)
        $holdingAppt = Appointment::create([
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'appointment_date' => Carbon::today()->addDays(1)->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '10:30:00',
            'status' => 'holding',
        ]);

        // Initialise session in confirmation state, mimicking flow after KVKK acceptance.
        $session = WhatsappSession::firstOrCreate(
            ['phone_number' => $phone],
            ['current_state' => 'MAIN_MENU', 'state_payload' => []]
        );
        $session->update([
            'current_state' => 'CONFIRMING_BOOKING',
            'state_payload' => [
                'patient_id' => $patient->id,
                'doctor_id' => $doctor->id,
                'selected_date' => $holdingAppt->appointment_date,
                'appointment_id' => $holdingAppt->id,
                'slot_display' => $holdingAppt->start_time . ' - ' . $holdingAppt->end_time,
                'kvkk_accepted' => true,
            ],
        ]);

        $flow = $this->makeFlowService();
        // Simulate user confirming the booking.
        $flow->handle($phone, 'booking_yes');

        // Refresh appointment and assert KVKK consent persisted on appointment.
        $holdingAppt->refresh();
        $this->assertTrue($holdingAppt->kvkk_consent_accepted);
        $this->assertNotNull($holdingAppt->kvkk_consent_date);

        // Start a second booking for the same patient.
        $flow->handle($phone, 'main_new_appointment');
        // Choose existing patient (latest)
        $flow->handle($phone, 'patient_latest');
        // Session should now be awaiting KVKK consent again.
        $session->refresh();
        $this->assertEquals('AWAITING_KVKK_CONSENT', $session->current_state);
    }
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
        /**
     * Focused test: Confirm KVKK consent is persisted on the appointment record and a new booking again asks for consent.
     */
    public function testConfirmedAppointmentStoresKvkkConsentAtAppointmentLevelAndRequiresConsentOnNewBooking(): void
    {
        $phone = '905556667788';
        $patient = Patient::factory()->create([
            'primary_phone' => $phone,
            'kvkk_consent_accepted' => false,
        ]);
        $doctor = Doctor::first();

        // Create a holding appointment directly (status = holding)
        $holdingAppt = Appointment::create([
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'appointment_date' => Carbon::today()->addDays(1)->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '10:30:00',
            'status' => 'holding',
        ]);

        // Initialise session in confirmation state, mimicking flow after KVKK acceptance.
        $session = WhatsappSession::firstOrCreate(
            ['phone_number' => $phone],
            ['current_state' => 'MAIN_MENU', 'state_payload' => []]
        );
        $session->update([
            'current_state' => 'CONFIRMING_BOOKING',
            'state_payload' => [
                'patient_id' => $patient->id,
                'doctor_id' => $doctor->id,
                'selected_date' => $holdingAppt->appointment_date,
                'appointment_id' => $holdingAppt->id,
                'slot_display' => $holdingAppt->start_time . ' - ' . $holdingAppt->end_time,
                'kvkk_accepted' => true,
            ],
        ]);

        $flow = $this->makeFlowService();
        // Simulate user confirming the booking.
        $flow->handle($phone, 'booking_yes');

        // Refresh appointment and assert KVKK consent persisted on appointment.
        $holdingAppt->refresh();
        $this->assertTrue($holdingAppt->kvkk_consent_accepted);
        $this->assertNotNull($holdingAppt->kvkk_consent_date);

        // Start a second booking for the same patient.
        $flow->handle($phone, 'main_new_appointment');
        // Choose existing patient (latest)
        $flow->handle($phone, 'patient_latest');
        // Session should now be awaiting KVKK consent again.
        $session->refresh();
        $this->assertEquals('AWAITING_KVKK_CONSENT', $session->current_state);
    }
}

    /** 18. Slot pagination traverses slots with separate cancel button in Arabic */
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
                'available_dates' => [['date' => '2026-11-08', 'day_name' => 'الأحد', 'formatted' => '2026-11-08']],
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
        $this->assertEquals('إلغاء والعودة', $cancelBtn['title']);

        // Next page
        $flow->handle($patient->primary_phone, 'slot_page_next');
        $count2 = count($this->sentMessages);
        $page2List = $this->sentMessages[$count2 - 2];
        $page2Rows = $page2List['sections'][0]['rows'];
        $this->assertLessThanOrEqual(10, count($page2Rows));

        $page2Ids = array_column($page2Rows, 'id');
        $this->assertContains('slot_page_prev', $page2Ids);
        /**
     * Focused test: Confirm KVKK consent is persisted on the appointment record and a new booking again asks for consent.
     */
    public function testConfirmedAppointmentStoresKvkkConsentAtAppointmentLevelAndRequiresConsentOnNewBooking(): void
    {
        $phone = '905556667788';
        $patient = Patient::factory()->create([
            'primary_phone' => $phone,
            'kvkk_consent_accepted' => false,
        ]);
        $doctor = Doctor::first();

        // Create a holding appointment directly (status = holding)
        $holdingAppt = Appointment::create([
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'appointment_date' => Carbon::today()->addDays(1)->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '10:30:00',
            'status' => 'holding',
        ]);

        // Initialise session in confirmation state, mimicking flow after KVKK acceptance.
        $session = WhatsappSession::firstOrCreate(
            ['phone_number' => $phone],
            ['current_state' => 'MAIN_MENU', 'state_payload' => []]
        );
        $session->update([
            'current_state' => 'CONFIRMING_BOOKING',
            'state_payload' => [
                'patient_id' => $patient->id,
                'doctor_id' => $doctor->id,
                'selected_date' => $holdingAppt->appointment_date,
                'appointment_id' => $holdingAppt->id,
                'slot_display' => $holdingAppt->start_time . ' - ' . $holdingAppt->end_time,
                'kvkk_accepted' => true,
            ],
        ]);

        $flow = $this->makeFlowService();
        // Simulate user confirming the booking.
        $flow->handle($phone, 'booking_yes');

        // Refresh appointment and assert KVKK consent persisted on appointment.
        $holdingAppt->refresh();
        $this->assertTrue($holdingAppt->kvkk_consent_accepted);
        $this->assertNotNull($holdingAppt->kvkk_consent_date);

        // Start a second booking for the same patient.
        $flow->handle($phone, 'main_new_appointment');
        // Choose existing patient (latest)
        $flow->handle($phone, 'patient_latest');
        // Session should now be awaiting KVKK consent again.
        $session->refresh();
        $this->assertEquals('AWAITING_KVKK_CONSENT', $session->current_state);
    }
}
    /**
     * Focused test: Confirm KVKK consent is persisted on the appointment record and a new booking again asks for consent.
     */
    public function testConfirmedAppointmentStoresKvkkConsentAtAppointmentLevelAndRequiresConsentOnNewBooking(): void
    {
        $phone = '905556667788';
        $patient = Patient::factory()->create([
            'primary_phone' => $phone,
            'kvkk_consent_accepted' => false,
        ]);
        $doctor = Doctor::first();

        // Create a holding appointment directly (status = holding)
        $holdingAppt = Appointment::create([
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'appointment_date' => Carbon::today()->addDays(1)->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '10:30:00',
            'status' => 'holding',
        ]);

        // Initialise session in confirmation state, mimicking flow after KVKK acceptance.
        $session = WhatsappSession::firstOrCreate(
            ['phone_number' => $phone],
            ['current_state' => 'MAIN_MENU', 'state_payload' => []]
        );
        $session->update([
            'current_state' => 'CONFIRMING_BOOKING',
            'state_payload' => [
                'patient_id' => $patient->id,
                'doctor_id' => $doctor->id,
                'selected_date' => $holdingAppt->appointment_date,
                'appointment_id' => $holdingAppt->id,
                'slot_display' => $holdingAppt->start_time . ' - ' . $holdingAppt->end_time,
                'kvkk_accepted' => true,
            ],
        ]);

        $flow = $this->makeFlowService();
        // Simulate user confirming the booking.
        $flow->handle($phone, 'booking_yes');

        // Refresh appointment and assert KVKK consent persisted on appointment.
        $holdingAppt->refresh();
        $this->assertTrue($holdingAppt->kvkk_consent_accepted);
        $this->assertNotNull($holdingAppt->kvkk_consent_date);

        // Start a second booking for the same patient.
        $flow->handle($phone, 'main_new_appointment');
        // Choose existing patient (latest)
        $flow->handle($phone, 'patient_latest');
        // Session should now be awaiting KVKK consent again.
        $session->refresh();
        $this->assertEquals('AWAITING_KVKK_CONSENT', $session->current_state);
    }
}
