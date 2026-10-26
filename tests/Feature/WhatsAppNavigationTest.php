<?php

namespace Tests\Feature;

use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Specialty;
use App\Models\WhatsappSession;
use App\Services\AppointmentBookingService;
use App\Services\WhatsAppFlowService;
use App\Services\WhatsAppCloudApiService;
use Illuminate\Support\Facades\Carbon;
use Tests\TestCase;
use Mockery;


/**
 * Tests for the new navigation rows and slot pagination in the WhatsApp flow.
 */
class WhatsAppNavigationTest extends TestCase
{
    /**
     * A fake API service that records sent messages for inspection.
     */
    protected $fakeApi;

    protected function setUp(): void
    {
        parent::setUp();
        // Use in‑memory DB for tests.
        $this->artisan('migrate:fresh --seed');

        // Mock the WhatsAppCloudApiService to capture outbound messages
        $this->fakeApi = Mockery::mock(WhatsAppCloudApiService::class);
        $test = $this;
        $this->fakeApi->shouldReceive('cleanPhoneNumber')->andReturnUsing(function($phone) {
            return preg_replace('/\D+/', '', $phone);
        });
        $this->fakeApi->shouldReceive('sendTextMessage')->andReturnUsing(function($to, $msg) use ($test) {
            $test->sentMessages[] = ['type' => 'text', 'to' => $to, 'msg' => $msg];
            return true;
        });
        $this->fakeApi->shouldReceive('sendButtonMessage')->andReturnUsing(function($to, $body, $buttons, $header = null, $footer = null) use ($test) {
            $test->sentMessages[] = ['type' => 'button', 'to' => $to, 'body' => $body, 'buttons' => $buttons, 'header' => $header, 'footer' => $footer];
            return true;
        });
        $this->fakeApi->shouldReceive('sendListMessage')->andReturnUsing(function($to, $body, $buttonText, $sections, $header = null, $footer = null) use ($test) {
            $test->sentMessages[] = ['type' => 'list', 'to' => $to, 'body' => $body, 'buttonText' => $buttonText, 'sections' => $sections, 'header' => $header, 'footer' => $footer];
            return true;
        });
        $this->sentMessages = [];
    }

    /**
     * Helper to build a flow service with a mocked BookingService that returns many slots.
     */
    protected function makeFlowService(int $slotCount = 10): WhatsAppFlowService
    {
        $bookingMock = Mockery::mock(AppointmentBookingService::class);
        // Return a doctor with available dates and slots.
        $bookingMock->shouldReceive('getAvailableDates')->andReturn(collect([
            ['date' => '2024-01-01', 'day_name' => 'الإثنين'],
        ]));
        $slots = [];
        for ($i = 0; $i < $slotCount; $i++) {
            $formattedIndex = str_pad($i, 2, '0', STR_PAD_LEFT);
            $slots[] = [
                'display' => "08:{$formattedIndex} - 08:" . str_pad($i + 1, 2, '0', STR_PAD_LEFT),
                'start_time' => "08:{$formattedIndex}:00",
                'end_time' => "08:" . str_pad($i + 1, 2, '0', STR_PAD_LEFT) . ":00"
            ];
        }
        $bookingMock->shouldReceive('getAvailableSlots')->andReturn(collect($slots));
        $bookingMock->shouldReceive('holdSlot')->andReturnUsing(function ($patient, $doctor, $date, $start, $end, $src) {
            return \App\Models\Appointment::create([
                'patient_id' => $patient->id,
                'doctor_id' => $doctor->id,
                'appointment_date' => $date,
                'start_time' => $start ?? '08:00:00',
                'end_time' => $end ?? '08:15:00',
                'status' => 'holding',
                'source' => $src ?? 'whatsapp',
            ]);
        });
        $bookingMock->shouldReceive('confirmAppointment')->andReturnUsing(function ($appt) { $appt->status = 'confirmed'; return $appt; });

        return new WhatsAppFlowService($this->fakeApi, $bookingMock);
    }

    /** @test */
    public function testIncludesNavigationRowsAndPaginateSlots()
    {
        // Create required models.
        $patient = Patient::factory()->create(['primary_phone' => '1234567890']);
        $specialty = Specialty::create(['name' => 'General', 'code' => 'GEN', 'description' => 'General specialty', 'is_active' => true]);
        $doctor = Doctor::factory()->create(['specialty_id' => $specialty->id, 'is_active' => true]);

        $flow = $this->makeFlowService(10);

        // Simulate the booking flow up to date selection.
        $session = WhatsappSession::firstOrCreate(['phone_number' => $patient->primary_phone, 'current_state' => 'SELECTING_DOCTOR']);
        $session->update(['current_state' => 'SELECTING_DOCTOR', 'state_payload' => ['patient_id' => $patient->id, 'specialty_id' => $specialty->id, 'doctor_ids' => [$doctor->id]]]);

        // Choose the doctor.
        $flow->handle($patient->primary_phone, 'doctor_' . $doctor->id);
        // Choose the date (the only available one).
        $flow->handle($patient->primary_phone, 'date_2024-01-01');

        // Page 1: 6 slots (0..5) + slot_page_next + main_menu + cancel_booking = 9 rows
        $last = end($this->sentMessages);
        $this->assertEquals('list', $last['type']);
        $rows = $last['sections'][0]['rows'];
        $this->assertLessThanOrEqual(10, count($rows));
        $this->assertCount(9, $rows);

        // First 6 rows are slots
        $this->assertStringContainsString('08:00', $rows[0]['title']);
        $this->assertEquals('slot_0', $rows[0]['id']);
        $this->assertEquals('slot_5', $rows[5]['id']);

        // Navigation rows: slot_page_next, main_menu, cancel_booking
        $this->assertEquals('slot_page_next', $rows[6]['id']);
        $this->assertEquals('main_menu', $rows[7]['id']);
        $this->assertEquals('cancel_booking', $rows[8]['id']);

        // Verify selecting slot_page_next advances to page 2 and shows remaining slots
        $flow->handle($patient->primary_phone, 'slot_page_next');
        $page2Last = end($this->sentMessages);
        $this->assertEquals('list', $page2Last['type']);
        $page2Rows = $page2Last['sections'][0]['rows'];
        $this->assertLessThanOrEqual(10, count($page2Rows));
        // Page 2 has 4 remaining slots (indexes 6, 7, 8, 9) + slot_page_prev + main_menu + cancel_booking = 7 rows
        $this->assertCount(7, $page2Rows);
        $this->assertEquals('slot_6', $page2Rows[0]['id']);
        $this->assertEquals('slot_7', $page2Rows[1]['id']);
        $this->assertEquals('slot_8', $page2Rows[2]['id']);
        $this->assertEquals('slot_9', $page2Rows[3]['id']);
        $this->assertEquals('slot_page_prev', $page2Rows[4]['id']);
        $this->assertEquals('main_menu', $page2Rows[5]['id']);
        $this->assertEquals('cancel_booking', $page2Rows[6]['id']);
    }

    /** @test */
    public function testMultiPageSlotPaginationTraversesAllSlotsWithoutDuplicatesOrSkips()
    {
        // Test with 25 slots across 5 pages:
        // Page 1: slots 0..5 (6 slots), Next, Main, Cancel (9 rows)
        // Page 2: slots 6..11 (6 slots), Prev, Next, Main, Cancel (10 rows)
        // Page 3: slots 12..17 (6 slots), Prev, Next, Main, Cancel (10 rows)
        // Page 4: slots 18..23 (6 slots), Prev, Next, Main, Cancel (10 rows)
        // Page 5: slot 24 (1 slot), Prev, Main, Cancel (4 rows)
        $totalSlots = 25;
        $flow = $this->makeFlowService($totalSlots);

        $patient = Patient::factory()->create(['primary_phone' => '1122334455']);
        $specialty = Specialty::create(['name' => 'Pediatrics', 'code' => 'PED', 'description' => 'Children clinic', 'is_active' => true]);
        $doctor = Doctor::factory()->create(['specialty_id' => $specialty->id, 'is_active' => true]);

        $session = WhatsappSession::firstOrCreate(['phone_number' => $patient->primary_phone, 'current_state' => 'SELECTING_DOCTOR']);
        $session->update(['current_state' => 'SELECTING_DOCTOR', 'state_payload' => ['patient_id' => $patient->id, 'specialty_id' => $specialty->id, 'doctor_ids' => [$doctor->id]]]);

        $flow->handle($patient->primary_phone, 'doctor_' . $doctor->id);
        $flow->handle($patient->primary_phone, 'date_2024-01-01');

        $seenSlotIds = [];
        $totalPages = (int) ceil($totalSlots / 6); // 5 pages

        for ($page = 1; $page <= $totalPages; $page++) {
            $lastMsg = end($this->sentMessages);
            $this->assertEquals('list', $lastMsg['type']);
            $rows = $lastMsg['sections'][0]['rows'];

            // WhatsApp list constraint: max 10 rows
            $this->assertLessThanOrEqual(10, count($rows), "Page {$page} must have at most 10 rows");

            // Extract slot rows and navigation rows
            $slotRows = array_filter($rows, fn($r) => str_starts_with($r['id'], 'slot_') && !in_array($r['id'], ['slot_page_prev', 'slot_page_next']));
            $rowIds = array_column($rows, 'id');

            // main_menu and cancel_booking must ALWAYS be present
            $this->assertContains('main_menu', $rowIds, "Page {$page} must contain main_menu");
            $this->assertContains('cancel_booking', $rowIds, "Page {$page} must contain cancel_booking");

            // Check prev/next navigation existence
            if ($page > 1) {
                $this->assertContains('slot_page_prev', $rowIds, "Page {$page} must have slot_page_prev");
            } else {
                $this->assertNotContains('slot_page_prev', $rowIds, "Page 1 must not have slot_page_prev");
            }

            if ($page < $totalPages) {
                $this->assertContains('slot_page_next', $rowIds, "Page {$page} must have slot_page_next");
            } else {
                $this->assertNotContains('slot_page_next', $rowIds, "Final page {$page} must not have slot_page_next");
            }

            // Verify slot count on this page
            $expectedSlotCount = min(6, $totalSlots - ($page - 1) * 6);
            $this->assertCount($expectedSlotCount, $slotRows, "Page {$page} slot count mismatch");

            // Record slot IDs and verify no duplicates
            foreach ($slotRows as $sRow) {
                $this->assertNotContains($sRow['id'], $seenSlotIds, "Slot {$sRow['id']} was duplicated on page {$page}");
                $seenSlotIds[] = $sRow['id'];
            }

            // Move to next page if not on final page
            if ($page < $totalPages) {
                $flow->handle($patient->primary_phone, 'slot_page_next');
            }
        }

        // Verify all 25 slots appeared exactly once in sequential order
        $this->assertCount($totalSlots, $seenSlotIds);
        for ($i = 0; $i < $totalSlots; $i++) {
            $this->assertEquals("slot_{$i}", $seenSlotIds[$i]);
        }

        // Verify navigating backwards with slot_page_prev
        $flow->handle($patient->primary_phone, 'slot_page_prev');
        $backMsg = end($this->sentMessages);
        $backRows = $backMsg['sections'][0]['rows'];
        $backRowIds = array_column($backRows, 'id');
        $this->assertContains('slot_18', $backRowIds);
        $this->assertContains('slot_23', $backRowIds);
        $this->assertContains('slot_page_prev', $backRowIds);
        $this->assertContains('slot_page_next', $backRowIds);
    }

    /** @test */
    public function testMainMenuPayloadResetsState()
    {
        $flow = $this->makeFlowService();
        $session = WhatsappSession::firstOrCreate(['phone_number' => '5550001111', 'current_state' => 'SELECTING_SPECIALTY']);
        $session->update(['current_state' => 'SELECTING_SPECIALTY', 'state_payload' => ['some' => 'data']]);

        $flow->handle('5550001111', 'main_menu');
        $session->refresh();
        $this->assertEquals('MAIN_MENU', $session->current_state);
        $this->assertEmpty($session->state_payload);
    }

    /** @test */
    public function testCancelBookingPayloadCancelsHoldingAndResets()
    {
        // Create a holding appointment with valid required fields
        $patient = Patient::factory()->create(['primary_phone' => '999888777']);
        $doctor = Doctor::factory()->create();
        $appointment = \App\Models\Appointment::create([
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'status' => 'holding',
            'appointment_date' => now()->toDateString(),
            'start_time' => '09:00:00',
            'end_time' => '09:30:00',
            'source' => 'whatsapp',
        ]);

        $flow = $this->makeFlowService();
        $session = WhatsappSession::firstOrCreate(['phone_number' => $patient->primary_phone, 'current_state' => 'SELECTING_SLOT']);
        $session->update([
            'current_state' => 'SELECTING_SLOT',
            'state_payload' => ['appointment_id' => $appointment->id, 'available_slots' => []]
        ]);
        $flow->handle($patient->primary_phone, 'cancel_booking');
        $appointment->refresh();
        $this->assertEquals('cancelled_by_patient', $appointment->status);
        $session->refresh();
        $this->assertEquals('MAIN_MENU', $session->current_state);
    }

    /** @test */
    public function testCancelBookingDoesNotCancelConfirmedAppointment()
    {
        // Confirmed appointments must never be cancelled via cancel_booking payload
        $patient = Patient::factory()->create(['primary_phone' => '888777666']);
        $doctor = Doctor::factory()->create();
        $appointment = \App\Models\Appointment::create([
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'status' => 'confirmed',
            'appointment_date' => now()->addDay()->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '10:30:00',
            'source' => 'whatsapp',
        ]);

        $flow = $this->makeFlowService();
        $session = WhatsappSession::firstOrCreate(['phone_number' => $patient->primary_phone, 'current_state' => 'SELECTING_SLOT']);
        $session->update([
            'current_state' => 'SELECTING_SLOT',
            'state_payload' => ['appointment_id' => $appointment->id]
        ]);
        $flow->handle($patient->primary_phone, 'cancel_booking');
        $appointment->refresh();
        $this->assertEquals('confirmed', $appointment->status);
        $session->refresh();
        $this->assertEquals('MAIN_MENU', $session->current_state);
    }
}
