<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\ClinicSetting;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Specialty;
use App\Models\WhatsappSession;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * Implementation of the WhatsApp conversation flow for clinic booking.
 */
class WhatsAppFlowService
{
    public function __construct(
        protected WhatsAppCloudApiService $apiService,
        protected AppointmentBookingService $bookingService
    ) {}

    /**
     * Entry point for incoming messages.
     */
    public function handle(string $fromPhone, string $messageBody): void
    {
        $cleanPhone = $this->apiService->cleanPhoneNumber($fromPhone);
        $text = trim($messageBody);

        // Load or create session
        $session = WhatsappSession::firstOrCreate(
            ['phone_number' => $cleanPhone],
            ['current_state' => 'MAIN_MENU', 'state_payload' => []]
        );

        $payload = $session->state_payload ?? [];

        // Session timeout – reset after 15 minutes of inactivity
        if ($session->last_interaction_at && Carbon::parse($session->last_interaction_at)->diffInMinutes(Carbon::now()) > 15) {
            $this->resetToMainMenu($session, $cleanPhone);
            return;
        }

        // Show main menu on greeting or initial empty message
        if ($session->current_state === 'MAIN_MENU' && (empty($text) || in_array(strtolower($text), ['hi', 'hello', 'مرحبا', 'start', 'hey']))) {
            $this->resetToMainMenu($session, $cleanPhone);
            return;
        }

        // Update interaction timestamp
        $session->update(['last_interaction_at' => Carbon::now()]);

        // Global flow cancel/restart via button IDs and common keywords
        if (in_array(strtolower($text), ['flow_cancel', 'cancel_booking', 'flow_restart', 'main_menu', 'cancel', '0', 'الغاء', 'إلغاء', 'خروج', 'menu', 'قائمة', 'ابدأ'])) {
            // If there's a holding appointment, cancel it safely without touching confirmed appointments
            if (!empty($payload['appointment_id'])) {
                $holding = Appointment::find($payload['appointment_id']);
                if ($holding && $holding->status === 'holding') {
                    $holding->update([
                        'status' => 'cancelled_by_patient',
                        'cancellation_reason' => 'Flow cancelled by user',
                        'cancelled_at' => Carbon::now(),
                    ]);
                }
            }
            $this->resetToMainMenu($session, $cleanPhone);
            return;
        }

        $state = $session->current_state;

        switch ($state) {
            case 'MAIN_MENU':
                $this->handleMainMenu($session, $cleanPhone, $text);
                break;
            case 'SELECTING_PATIENT':
                $this->handleSelectPatient($session, $cleanPhone, $text, $payload);
                break;
            case 'AWAITING_NEW_PATIENT_NAME':
                $this->handleNewPatientName($session, $cleanPhone, $text, $payload);
                break;
            case 'AWAITING_KVKK_CONSENT':
                $this->handleKvkkConsent($session, $cleanPhone, $text, $payload);
                break;
            case 'SELECTING_SPECIALTY':
                $this->handleSelectSpecialty($session, $cleanPhone, $text, $payload);
                break;
            case 'SELECTING_DOCTOR':
                $this->handleSelectDoctor($session, $cleanPhone, $text, $payload);
                break;
            case 'SELECTING_DATE':
                $this->handleSelectDate($session, $cleanPhone, $text, $payload);
                break;
            case 'SELECTING_SLOT':
                $this->handleSelectSlot($session, $cleanPhone, $text, $payload);
                break;
            case 'CONFIRMING_BOOKING':
                $this->handleConfirmBooking($session, $cleanPhone, $text, $payload);
                break;
            case 'SELECTING_CANCEL_APPT':
                $this->handleSelectCancelAppointment($session, $cleanPhone, $text, $payload);
                break;
            case 'CONFIRMING_CANCELLATION':
                $this->handleConfirmCancellation($session, $cleanPhone, $text, $payload);
                break;
            default:
                $this->resetToMainMenu($session, $cleanPhone);
        }
    }

    /**
     * Display Main Menu with 3 separate interactive reply buttons.
     */
    public function resetToMainMenu(WhatsappSession $session, string $phone): void
    {
        $session->update([
            'current_state' => 'MAIN_MENU',
            'state_payload' => [],
            'last_interaction_at' => Carbon::now(),
        ]);

        $body = "Welcome to Al-Shifa Medical Clinic.\n\nThis bot helps you manage your clinic appointments. Please select one of the options below.";

        $buttons = [
            [
                'id' => 'main_new_appointment',
                'title' => 'New Appointment',
                'type' => 'reply',
                'reply' => ['id' => 'main_new_appointment', 'title' => 'New Appointment'],
            ],
            [
                'id' => 'main_view_appointments',
                'title' => 'View / Reschedule Appointments',
                'type' => 'reply',
                'reply' => ['id' => 'main_view_appointments', 'title' => 'View / Reschedule Appointments'],
            ],
            [
                'id' => 'main_cancel_appointments',
                'title' => 'Cancel Active Appointments',
                'type' => 'reply',
                'reply' => ['id' => 'main_cancel_appointments', 'title' => 'Cancel Active Appointments'],
            ],
        ];

        $this->apiService->sendButtonMessage($phone, $body, $buttons);
    }

    /**
     * Handle Main Menu actions.
     */
    protected function handleMainMenu(WhatsappSession $session, string $phone, string $choice): void
    {
        if (in_array($choice, ['1', 'main_new_appointment', 'main_menu_book'])) {
            $this->initiateBookingFlow($session, $phone);
        } elseif (in_array($choice, ['2', 'main_view_appointments', 'main_menu_view'])) {
            $this->showUpcomingAppointments($session, $phone);
        } elseif (in_array($choice, ['3', 'main_cancel_appointments', 'main_menu_cancel'])) {
            $this->initiateCancellationFlow($session, $phone);
        } else {
            $this->apiService->sendTextMessage($phone, "Please select one of the options below by tapping one of the buttons.");
            $this->resetToMainMenu($session, $phone);
        }
    }

    /**
     * Retrieve the single most recently registered or booked patient for this phone number.
     */
    protected function getLatestPatientForPhone(string $phone): ?Patient
    {
        $patients = Patient::where('primary_phone', $phone)->get();
        if ($patients->isEmpty()) {
            return null;
        }

        if ($patients->count() === 1) {
            return $patients->first();
        }

        // Check if any patient has recent appointments
        $latestWithAppt = Patient::where('primary_phone', $phone)
            ->whereHas('appointments')
            ->get()
            ->sortByDesc(function ($p) {
                return $p->appointments()->max('id') ?? 0;
            })
            ->first();

        $latestRegistered = Patient::where('primary_phone', $phone)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first();

        if ($latestWithAppt && $latestRegistered && $latestWithAppt->id !== $latestRegistered->id) {
            $latestApptDate = $latestWithAppt->appointments()->latest('id')->first()?->created_at;
            if ($latestApptDate && $latestApptDate->gt($latestRegistered->updated_at)) {
                return $latestWithAppt;
            }
        }

        return $latestRegistered;
    }

    /**
     * Step 1: Patient Selection.
     * Shows only the single latest patient (if exists), Register New Patient, and Cancel button.
     */
    protected function initiateBookingFlow(WhatsappSession $session, string $phone): void
    {
        $latestPatient = $this->getLatestPatientForPhone($phone);

        $session->update([
            'current_state' => 'SELECTING_PATIENT',
            'state_payload' => [
                'latest_patient_id' => $latestPatient?->id,
            ],
            'last_interaction_at' => Carbon::now(),
        ]);

        $buttons = [];
        if ($latestPatient) {
            $buttons[] = [
                'id' => 'patient_latest',
                'title' => $latestPatient->full_name,
                'type' => 'reply',
                'reply' => ['id' => 'patient_latest', 'title' => $latestPatient->full_name],
            ];
        }

        $buttons[] = [
            'id' => 'patient_new',
            'title' => 'Register New Patient',
            'type' => 'reply',
            'reply' => ['id' => 'patient_new', 'title' => 'Register New Patient'],
        ];

        $buttons[] = [
            'id' => 'flow_cancel',
            'title' => 'Cancel and Return to Main Menu',
            'type' => 'reply',
            'reply' => ['id' => 'flow_cancel', 'title' => 'Cancel and Return to Main Menu'],
        ];

        $this->apiService->sendButtonMessage($phone, "Please select a patient:", $buttons);
    }

    /**
     * Handle Patient Selection choices.
     */
    protected function handleSelectPatient(WhatsappSession $session, string $phone, string $choice, array $payload): void
    {
        $latestPatientId = $payload['latest_patient_id'] ?? null;

        // Choose latest patient
        $isLatestChosen = ($choice === 'patient_latest') ||
            ($latestPatientId && $choice === 'patient_' . $latestPatientId) ||
            ($latestPatientId && $choice === '1');

        if ($isLatestChosen && $latestPatientId) {
            $patient = Patient::find($latestPatientId);
            if ($patient && $patient->is_blocked) {
                $this->apiService->sendTextMessage($phone, "Sorry, this patient account is blocked. Please contact the clinic.");
                $this->resetToMainMenu($session, $phone);
                return;
            }

            // KVKK consent is required when reusing an existing patient
            $session->update([
                'current_state' => 'AWAITING_KVKK_CONSENT',
                'state_payload' => ['patient_id' => $patient->id],
            ]);
            $this->sendKvkkConsentMessage($session, $phone, ['patient_id' => $patient->id]);
            return;
        }

        // Choose to register new patient
        $isNewPatientChosen = ($choice === 'patient_new' || $choice === 'new_patient') ||
            (!$latestPatientId && $choice === '1') ||
            ($latestPatientId && $choice === '2');

        if ($isNewPatientChosen) {
            $session->update([
                'current_state' => 'AWAITING_NEW_PATIENT_NAME',
                'state_payload' => [],
            ]);
            $this->apiService->sendTextMessage($phone, "Please enter the patient's full name:");
            return;
        }

        // Invalid choice – re-display patient selection
        $this->initiateBookingFlow($session, $phone);
    }

    /**
     * Handle New Patient Name input.
     */
    protected function handleNewPatientName(WhatsappSession $session, string $phone, string $name, array $payload): void
    {
        $trimmedName = trim($name);
        if (mb_strlen($trimmedName) < 2) {
            $this->apiService->sendTextMessage($phone, "Please enter a valid patient full name (at least 2 characters):");
            return;
        }

        // Check if patient already exists for this phone number to avoid unnecessary duplicates
        $patient = Patient::where('primary_phone', $phone)
            ->whereRaw('LOWER(TRIM(full_name)) = ?', [mb_strtolower($trimmedName)])
            ->first();

        if (!$patient) {
            $patient = Patient::create([
                'primary_phone' => $phone,
                'full_name' => $trimmedName,
                'kvkk_consent_accepted' => true,
                'kvkk_consent_date' => Carbon::now(),
            ]);
        } else {
            $patient->update([
                'kvkk_consent_accepted' => true,
                'kvkk_consent_date' => Carbon::now(),
            ]);
            $patient->touch();
        }

        // Touch to ensure this patient is the latest registered
        $patient->touch();

        // Continue directly to clinic selection
        $session->update([
            'current_state' => 'SELECTING_SPECIALTY',
            'state_payload' => [
                'patient_id' => $patient->id,
                'kvkk_accepted' => true,
            ],
        ]);

        $this->displaySpecialties($phone, $session, $patient->id);
    }

    /**
     * Send KVKK Consent message with clean Yes / No reply buttons.
     */
    protected function sendKvkkConsentMessage(WhatsappSession $session, string $phone, array $payload): void
    {
        $body = "In order to proceed with your booking, please confirm your consent to our Personal Data Protection (KVKK) policy.\n\nDo you agree to the KVKK policy?";

        $buttons = [
            [
                'id' => 'kvkk_yes',
                'title' => 'Yes',
                'type' => 'reply',
                'reply' => ['id' => 'kvkk_yes', 'title' => 'Yes'],
            ],
            [
                'id' => 'kvkk_no',
                'title' => 'No',
                'type' => 'reply',
                'reply' => ['id' => 'kvkk_no', 'title' => 'No'],
            ],
        ];

        $this->apiService->sendButtonMessage($phone, $body, $buttons);
    }

    /**
     * Handle KVKK Consent response.
     */
    protected function handleKvkkConsent(WhatsappSession $session, string $phone, string $text, array $payload): void
    {
        if (in_array(strtolower($text), ['kvkk_yes', 'yes', '1', 'نعم'])) {
            $payload = array_merge($payload, ['kvkk_accepted' => true]);
            $patientId = $payload['patient_id'] ?? null;

            if ($patientId) {
                $patient = Patient::find($patientId);
                if ($patient) {
                    $patient->update([
                        'kvkk_consent_accepted' => true,
                        'kvkk_consent_date' => Carbon::now(),
                    ]);
                }

                $session->update([
                    'current_state' => 'SELECTING_SPECIALTY',
                    'state_payload' => $payload,
                ]);

                $this->displaySpecialties($phone, $session, $patientId);
            } else {
                $session->update([
                    'current_state' => 'AWAITING_NEW_PATIENT_NAME',
                    'state_payload' => $payload,
                ]);
                $this->apiService->sendTextMessage($phone, "Please enter the patient's full name:");
            }
        } elseif (in_array(strtolower($text), ['kvkk_no', 'no', '2', 'لا'])) {
            $this->apiService->sendTextMessage($phone, "Booking cancelled because KVKK consent was not provided.");
            $this->resetToMainMenu($session, $phone);
        } else {
            // Re-prompt KVKK message
            $this->sendKvkkConsentMessage($session, $phone, $payload);
        }
    }

    /**
     * Step 2: Clinic Selection.
     * Displays clinics as a WhatsApp interactive list, with a separate cancel button outside.
     */
    protected function displaySpecialties(string $phone, WhatsappSession $session, int $patientId): void
    {
        $specialties = Specialty::where('is_active', true)->get();
        if ($specialties->isEmpty()) {
            $this->apiService->sendTextMessage($phone, "No clinics are currently available for booking.");
            $this->resetToMainMenu($session, $phone);
            return;
        }

        $session->update([
            'current_state' => 'SELECTING_SPECIALTY',
            'state_payload' => [
                'patient_id' => $patientId,
                'specialty_ids' => $specialties->pluck('id')->toArray(),
            ],
        ]);

        $rows = [];
        foreach ($specialties as $spec) {
            $rows[] = ['id' => 'clinic_' . $spec->id, 'title' => $spec->name];
        }

        $sections = [['title' => 'Clinics', 'rows' => $rows]];
        $this->apiService->sendListMessage($phone, "Please select a clinic:", "Select Clinic", $sections);

        // Separate cancel button outside the list
        $this->sendSeparateCancelButton($phone);
    }

    /**
     * Handle Clinic Selection.
     */
    protected function handleSelectSpecialty(WhatsappSession $session, string $phone, string $choice, array $payload): void
    {
        $specialtyIds = $payload['specialty_ids'] ?? [];
        $selectedId = null;

        if (Str::startsWith($choice, 'clinic_')) {
            $selectedId = (int) substr($choice, 7);
        } elseif (Str::startsWith($choice, 'specialty_')) {
            $selectedId = (int) substr($choice, 10);
        } else {
            $index = ((int) $choice) - 1;
            if (isset($specialtyIds[$index])) {
                $selectedId = $specialtyIds[$index];
            }
        }

        if (!$selectedId || !in_array($selectedId, $specialtyIds)) {
            $this->apiService->sendTextMessage($phone, "Invalid clinic selection. Please select a clinic from the list.");
            $this->displaySpecialties($phone, $session, $payload['patient_id']);
            return;
        }

        $specialtyId = $selectedId;
        $doctors = Doctor::where('specialty_id', $specialtyId)->where('is_active', true)->get();
        if ($doctors->isEmpty()) {
            $this->apiService->sendTextMessage($phone, "No doctors are currently available for this clinic.");
            $this->displaySpecialties($phone, $session, $payload['patient_id']);
            return;
        }

        $session->update([
            'current_state' => 'SELECTING_DOCTOR',
            'state_payload' => array_merge($payload, [
                'specialty_id' => $specialtyId,
                'doctor_ids' => $doctors->pluck('id')->toArray(),
            ]),
        ]);

        $rows = [];
        foreach ($doctors as $doc) {
            $title = $doc->title ? " ({$doc->title})" : '';
            $rows[] = ['id' => 'doctor_' . $doc->id, 'title' => trim("{$doc->full_name}{$title}")];
        }

        $sections = [['title' => 'Doctors', 'rows' => $rows]];
        $this->apiService->sendListMessage($phone, "Please select a doctor:", "Select Doctor", $sections);

        // Separate cancel button outside the list
        $this->sendSeparateCancelButton($phone);
    }

    /**
     * Handle Doctor Selection.
     */
    protected function handleSelectDoctor(WhatsappSession $session, string $phone, string $choice, array $payload): void
    {
        $doctorIds = $payload['doctor_ids'] ?? [];
        $selectedId = null;

        if (Str::startsWith($choice, 'doctor_')) {
            $selectedId = (int) substr($choice, 7);
        } else {
            $index = ((int) $choice) - 1;
            if (isset($doctorIds[$index])) {
                $selectedId = $doctorIds[$index];
            }
        }

        if (!$selectedId || !in_array($selectedId, $doctorIds)) {
            $this->apiService->sendTextMessage($phone, "Invalid doctor selection. Please select a doctor from the list.");
            return;
        }

        $doctorId = $selectedId;
        $doctor = Doctor::find($doctorId);
        $availableDates = $this->bookingService->getAvailableDates($doctor, 8);

        if ($availableDates->isEmpty()) {
            $this->apiService->sendTextMessage($phone, "No appointment dates are available for this doctor in the coming days.");
            $this->resetToMainMenu($session, $phone);
            return;
        }

        $session->update([
            'current_state' => 'SELECTING_DATE',
            'state_payload' => array_merge($payload, [
                'doctor_id' => $doctorId,
                'available_dates' => $availableDates->toArray(),
            ]),
        ]);

        $rows = [];
        foreach ($availableDates->take(8) as $d) {
            $rows[] = ['id' => 'date_' . $d['date'], 'title' => "{$d['formatted']} ({$d['day_name']})"];
        }

        $sections = [['title' => 'Available Dates', 'rows' => $rows]];
        $this->apiService->sendListMessage($phone, "Please select a suitable appointment date:", "Select Date", $sections);

        // Separate cancel button outside the list
        $this->sendSeparateCancelButton($phone);
    }

    /**
     * Handle Date Selection.
     */
    protected function handleSelectDate(WhatsappSession $session, string $phone, string $choice, array $payload): void
    {
        $availableDates = $payload['available_dates'] ?? [];
        $selectedDate = null;

        if (Str::startsWith($choice, 'date_')) {
            $selectedDate = substr($choice, 5);
        } else {
            $index = ((int) $choice) - 1;
            if (isset($availableDates[$index])) {
                $selectedDate = $availableDates[$index]['date'];
            }
        }

        if (!$selectedDate) {
            $this->apiService->sendTextMessage($phone, "Invalid date selection. Please select a date from the list.");
            return;
        }

        $doctor = Doctor::find($payload['doctor_id']);
        $slots = $this->bookingService->getAvailableSlots($doctor, $selectedDate);

        if ($slots->isEmpty()) {
            $this->apiService->sendTextMessage($phone, "All slots for this date are fully booked. Please select another date.");
            return;
        }

        $slotsArray = $slots->toArray();

        $session->update([
            'current_state' => 'SELECTING_SLOT',
            'state_payload' => array_merge($payload, [
                'selected_date' => $selectedDate,
                'available_slots' => $slotsArray,
                'slot_page' => 1,
            ]),
        ]);

        $this->displaySlotsList($phone, $selectedDate, $slotsArray, 1);
    }

    /**
     * Display Available Time Slots list and separate cancel button.
     */
    protected function displaySlotsList(string $phone, string $selectedDate, array $slotsArray, int $page): void
    {
        $totalSlots = count($slotsArray);
        // Fixed capacity per page: 8 slots, leaving room for prev/next navigation rows (total rows <= 10)
        $perPage = 8;
        $offset = ($page - 1) * $perPage;

        $pageSlots = array_slice($slotsArray, $offset, $perPage, true);
        $rows = [];
        foreach ($pageSlots as $idx => $s) {
            $rows[] = ['id' => 'slot_' . $idx, 'title' => $s['display']];
        }

        if ($page > 1) {
            $rows[] = ['id' => 'slot_page_prev', 'title' => 'Previous Slots'];
        }
        if ($offset + count($pageSlots) < $totalSlots) {
            $rows[] = ['id' => 'slot_page_next', 'title' => 'More Slots'];
        }

        $sections = [['title' => 'Available Times', 'rows' => $rows]];
        $this->apiService->sendListMessage($phone, "Please select an available appointment time:", "Select Time", $sections);

        // Separate cancel button outside the list
        $this->sendSeparateCancelButton($phone);
    }

    /**
     * Handle Slot Selection.
     */
    protected function handleSelectSlot(WhatsappSession $session, string $phone, string $choice, array $payload): void
    {
        $slots = $payload['available_slots'] ?? [];

        // Handle pagination actions
        if ($choice === 'slot_page_next' || $choice === 'slot_page_prev') {
            $currentPage = $payload['slot_page'] ?? 1;
            $newPage = $choice === 'slot_page_next' ? $currentPage + 1 : max(1, $currentPage - 1);
            $payload['slot_page'] = $newPage;
            $session->update(['state_payload' => $payload]);

            $this->displaySlotsList($phone, $payload['selected_date'], $slots, $newPage);
            return;
        }

        $index = null;
        if (Str::startsWith($choice, 'slot_')) {
            $index = (int) substr($choice, 5);
        } else {
            $index = ((int) $choice) - 1;
        }

        if (!isset($slots[$index])) {
            $this->apiService->sendTextMessage($phone, "Invalid time slot selected. Please choose a slot from the list.");
            return;
        }

        $slot = $slots[$index];
        $doctor = Doctor::find($payload['doctor_id']);
        $patient = Patient::find($payload['patient_id']);

        // Release any existing temporary holding appointment before holding a new slot
        if (!empty($payload['appointment_id'])) {
            $existingHold = Appointment::find($payload['appointment_id']);
            if ($existingHold && $existingHold->status === 'holding') {
                $existingHold->update([
                    'status' => 'cancelled_by_patient',
                    'cancellation_reason' => 'User selected a different slot',
                    'cancelled_at' => Carbon::now(),
                ]);
            }
        }

        try {
            // Revalidate slot and create temporary reservation
            $holdingAppt = $this->bookingService->holdSlot(
                $patient,
                $doctor,
                $payload['selected_date'],
                $slot['start_time'],
                $slot['end_time'],
                'whatsapp'
            );

            $session->update([
                'current_state' => 'CONFIRMING_BOOKING',
                'state_payload' => array_merge($payload, [
                    'appointment_id' => $holdingAppt->id,
                    'slot_display' => $slot['display'],
                ]),
            ]);

            $clinicName = $doctor->specialty?->name ?? 'Clinic';

            $reviewMsg = "Appointment Details\n\n" .
                "Patient: {$patient->full_name}\n" .
                "Clinic: {$clinicName}\n" .
                "Doctor: {$doctor->full_name}\n" .
                "Date: {$payload['selected_date']}\n" .
                "Time: {$slot['display']}\n\n" .
                "Please review the appointment details.\n\n" .
                "Do you want to proceed?";

            $buttons = [
                [
                    'id' => 'booking_yes',
                    'title' => 'Yes',
                    'type' => 'reply',
                    'reply' => ['id' => 'booking_yes', 'title' => 'Yes'],
                ],
                [
                    'id' => 'booking_no',
                    'title' => 'No',
                    'type' => 'reply',
                    'reply' => ['id' => 'booking_no', 'title' => 'No'],
                ],
            ];

            $this->apiService->sendButtonMessage($phone, $reviewMsg, $buttons);

        } catch (Throwable $e) {
            $this->apiService->sendTextMessage($phone, $e->getMessage());
            // Re-fetch slots and redisplay
            $availableSlots = $this->bookingService->getAvailableSlots($doctor, $payload['selected_date']);
            $slotsArray = $availableSlots->toArray();
            $payload['available_slots'] = $slotsArray;
            $session->update(['state_payload' => $payload]);
            $this->displaySlotsList($phone, $payload['selected_date'], $slotsArray, 1);
        }
    }

    /**
     * Handle Appointment Review and Confirmation.
     */
    protected function handleConfirmBooking(WhatsappSession $session, string $phone, string $choice, array $payload): void
    {
        $apptId = $payload['appointment_id'] ?? null;
        $appointment = Appointment::find($apptId);

        // Disallow selecting another slot while a pending confirmation is active
        if (Str::startsWith($choice, 'slot_')) {
            $this->apiService->sendTextMessage($phone, "You have a pending appointment confirmation. Please reply Yes to confirm or No to cancel.");
            $buttons = [
                ['id' => 'booking_yes', 'title' => 'Yes', 'type' => 'reply', 'reply' => ['id' => 'booking_yes', 'title' => 'Yes']],
                ['id' => 'booking_no', 'title' => 'No', 'type' => 'reply', 'reply' => ['id' => 'booking_no', 'title' => 'No']],
            ];
            $this->apiService->sendButtonMessage($phone, "Do you want to proceed with your booking?", $buttons);
            return;
        }

        // If user presses No
        if (in_array(strtolower($choice), ['booking_no', 'confirm_no', 'no', '2', 'لا'])) {
            if ($appointment && $appointment->status === 'holding') {
                $appointment->update([
                    'status' => 'cancelled_by_patient',
                    'cancellation_reason' => 'User rejected booking confirmation',
                    'cancelled_at' => Carbon::now(),
                ]);
            }
            $session->update([
                'current_state' => 'MAIN_MENU',
                'state_payload' => [],
            ]);
            $this->resetToMainMenu($session, $phone);
            return;
        }

        // If user presses Yes
        if (in_array(strtolower($choice), ['booking_yes', 'confirm_yes', 'yes', '1', 'نعم'])) {
            // Idempotency: if already confirmed (repeated webhook delivery), send confirmation and reset
            if ($appointment && $appointment->status === 'confirmed') {
                $this->sendConfirmationSuccessMessage($phone, $appointment, $payload['slot_display'] ?? null);
                $session->update([
                    'current_state' => 'MAIN_MENU',
                    'state_payload' => [],
                ]);
                $this->resetToMainMenu($session, $phone);
                return;
            }

            if (!$appointment || $appointment->status !== 'holding') {
                $this->apiService->sendTextMessage($phone, "The reservation is no longer pending. Please start a new booking.");
                $this->resetToMainMenu($session, $phone);
                return;
            }

            // Check if holding reservation has expired
            if ($appointment->holding_expires_at && Carbon::now()->gt($appointment->holding_expires_at)) {
                $appointment->update([
                    'status' => 'cancelled_by_clinic',
                    'cancellation_reason' => 'Reservation expired',
                    'cancelled_at' => Carbon::now(),
                ]);
                $this->apiService->sendTextMessage($phone, "Your temporary reservation has expired. Please select an available appointment time again.");
                // Return to slot selection
                $this->handleSelectDate($session, $phone, 'date_' . $payload['selected_date'], $payload);
                return;
            }

            try {
                // Confirm the exact pending reservation
                $confirmed = $this->bookingService->confirmAppointment($appointment);
                $confirmed->update([
                    'kvkk_consent_accepted' => true,
                    'kvkk_consent_date' => Carbon::now(),
                ]);

                // Update patient touched timestamp
                $confirmed->patient?->touch();

                // Send clean professional confirmation message
                $this->sendConfirmationSuccessMessage($phone, $confirmed, $payload['slot_display'] ?? null);

                // Clear pending booking state and automatically display main menu
                $session->update([
                    'current_state' => 'MAIN_MENU',
                    'state_payload' => [],
                ]);
                $this->resetToMainMenu($session, $phone);

            } catch (Throwable $e) {
                $this->apiService->sendTextMessage($phone, $e->getMessage());
                $this->resetToMainMenu($session, $phone);
            }
            return;
        }

        // Any other unrecognized input: re-prompt confirmation without cancelling
        $buttons = [
            ['id' => 'booking_yes', 'title' => 'Yes', 'type' => 'reply', 'reply' => ['id' => 'booking_yes', 'title' => 'Yes']],
            ['id' => 'booking_no', 'title' => 'No', 'type' => 'reply', 'reply' => ['id' => 'booking_no', 'title' => 'No']],
        ];
        $this->apiService->sendButtonMessage($phone, "Please review your appointment details and select Yes to proceed or No to cancel:", $buttons);
    }

    /**
     * Send final confirmation message in clean, professional plain text.
     */
    protected function sendConfirmationSuccessMessage(string $phone, Appointment $appointment, ?string $slotDisplay = null): void
    {
        $clinicName = $appointment->doctor->specialty?->name ?? ClinicSetting::get('clinic_name', 'Al-Shifa Medical Clinic');
        $timeDisplay = $slotDisplay ?: (substr($appointment->start_time, 0, 5) . ' - ' . substr($appointment->end_time, 0, 5));
        $dateDisplay = Carbon::parse($appointment->appointment_date)->format('Y-m-d');

        $msg = "Your appointment has been confirmed successfully.\n\n" .
            "Appointment Details\n\n" .
            "Patient: {$appointment->patient->full_name}\n" .
            "Clinic: {$clinicName}\n" .
            "Doctor: {$appointment->doctor->full_name}\n" .
            "Date: {$dateDisplay}\n" .
            "Time: {$timeDisplay}\n\n" .
            "Thank you for choosing Al-Shifa Medical Clinic.";

        $this->apiService->sendTextMessage($phone, $msg);
    }

    /**
     * Send separate cancel button below a list message.
     */
    protected function sendSeparateCancelButton(string $phone): void
    {
        $buttons = [
            [
                'id' => 'flow_cancel',
                'title' => 'Cancel and Return to Main Menu',
                'type' => 'reply',
                'reply' => ['id' => 'flow_cancel', 'title' => 'Cancel and Return to Main Menu'],
            ],
        ];

        $this->apiService->sendButtonMessage($phone, "To cancel and return to the main menu:", $buttons);
    }

    /**
     * Show upcoming confirmed appointments.
     */
    protected function showUpcomingAppointments(WhatsappSession $session, string $phone): void
    {
        $patientIds = Patient::where('primary_phone', $phone)->pluck('id');
        $appointments = Appointment::with(['doctor.specialty', 'patient'])
            ->whereIn('patient_id', $patientIds)
            ->where('appointment_date', '>=', Carbon::today()->toDateString())
            ->where('status', 'confirmed')
            ->orderBy('appointment_date')
            ->orderBy('start_time')
            ->get();

        if ($appointments->isEmpty()) {
            $this->apiService->sendTextMessage($phone, "You do not have any upcoming confirmed appointments.");
            $this->resetToMainMenu($session, $phone);
            return;
        }

        $msg = "Upcoming Appointments\n\n";
        foreach ($appointments as $idx => $a) {
            $num = $idx + 1;
            $clinicName = $a->doctor->specialty?->name ?? 'Clinic';
            $msg .= "{$num}. Reference: {$a->reference_code}\n" .
                "Patient: {$a->patient->full_name}\n" .
                "Clinic: {$clinicName}\n" .
                "Doctor: {$a->doctor->full_name}\n" .
                "Date: {$a->appointment_date->format('Y-m-d')}\n" .
                "Time: " . substr($a->start_time, 0, 5) . " - " . substr($a->end_time, 0, 5) . "\n\n";
        }

        $this->apiService->sendTextMessage($phone, trim($msg));
        $this->resetToMainMenu($session, $phone);
    }

    /**
     * Step: Cancellation Flow.
     */
    protected function initiateCancellationFlow(WhatsappSession $session, string $phone): void
    {
        $patientIds = Patient::where('primary_phone', $phone)->pluck('id');
        $appointments = Appointment::with(['doctor.specialty', 'patient'])
            ->whereIn('patient_id', $patientIds)
            ->where('appointment_date', '>=', Carbon::today()->toDateString())
            ->where('status', 'confirmed')
            ->orderBy('appointment_date')
            ->orderBy('start_time')
            ->get();

        if ($appointments->isEmpty()) {
            $this->apiService->sendTextMessage($phone, "You do not have any active appointments that can be cancelled.");
            $this->resetToMainMenu($session, $phone);
            return;
        }

        $session->update([
            'current_state' => 'SELECTING_CANCEL_APPT',
            'state_payload' => [
                'cancellable_ids' => $appointments->pluck('id')->toArray(),
            ],
        ]);

        $msg = "Please reply with the number of the appointment you wish to cancel:\n\n";
        foreach ($appointments as $idx => $a) {
            $num = $idx + 1;
            $msg .= "{$num}. Reference: [{$a->reference_code}] with {$a->doctor->full_name} on {$a->appointment_date->format('Y-m-d')} at " . substr($a->start_time, 0, 5) . "\n";
        }

        $this->apiService->sendTextMessage($phone, trim($msg));
    }

    /**
     * Handle Cancel Appointment Choice.
     */
    protected function handleSelectCancelAppointment(WhatsappSession $session, string $phone, string $choice, array $payload): void
    {
        $cancellableIds = $payload['cancellable_ids'] ?? [];
        $index = ((int) $choice) - 1;

        if (!isset($cancellableIds[$index])) {
            $this->apiService->sendTextMessage($phone, "Invalid selection. Please reply with the number of the appointment to cancel.");
            return;
        }

        $appointment = Appointment::find($cancellableIds[$index]);
        if (!$appointment) {
            $this->apiService->sendTextMessage($phone, "The appointment was not found. Please try again.");
            $this->resetToMainMenu($session, $phone);
            return;
        }

        $session->update([
            'current_state' => 'CONFIRMING_CANCELLATION',
            'state_payload' => ['appointment_id' => $appointment->id],
        ]);

        $confirmMsg = "Are you sure you want to cancel your appointment [{$appointment->reference_code}] with {$appointment->doctor->full_name} on {$appointment->appointment_date->format('Y-m-d')}?";
        $buttons = [
            ['id' => 'cancel_yes', 'title' => 'Yes', 'type' => 'reply', 'reply' => ['id' => 'cancel_yes', 'title' => 'Yes']],
            ['id' => 'cancel_no', 'title' => 'No', 'type' => 'reply', 'reply' => ['id' => 'cancel_no', 'title' => 'No']],
        ];

        $this->apiService->sendButtonMessage($phone, $confirmMsg, $buttons);
    }

    /**
     * Handle Confirmation of Cancellation.
     */
    protected function handleConfirmCancellation(WhatsappSession $session, string $phone, string $choice, array $payload): void
    {
        $appointmentId = $payload['appointment_id'] ?? null;
        $appointment = Appointment::find($appointmentId);

        if (!$appointment) {
            $this->apiService->sendTextMessage($phone, "The appointment was not found or was already removed.");
            $this->resetToMainMenu($session, $phone);
            return;
        }

        $patient = $appointment->patient;
        if (!$patient || $patient->primary_phone !== $phone) {
            $this->apiService->sendTextMessage($phone, "You are not authorized to cancel this appointment.");
            $this->resetToMainMenu($session, $phone);
            return;
        }

        $nonCancellable = ['cancelled_by_patient', 'cancelled_by_clinic', 'completed'];
        if (in_array($appointment->status, $nonCancellable)) {
            $this->apiService->sendTextMessage($phone, "This appointment cannot be cancelled.");
            $this->resetToMainMenu($session, $phone);
            return;
        }

        if (in_array(strtolower($choice), ['cancel_yes', 'yes', '1'])) {
            try {
                $this->bookingService->cancelAppointment($appointment, 'Cancelled by patient via WhatsApp', 'patient');
                $msg = "Your appointment [{$appointment->reference_code}] with {$appointment->doctor->full_name} on {$appointment->appointment_date->format('Y-m-d')} at " . substr($appointment->start_time, 0, 5) . " has been cancelled.";
                $this->apiService->sendTextMessage($phone, $msg);
            } catch (Throwable $e) {
                $this->apiService->sendTextMessage($phone, "Unable to cancel appointment: " . $e->getMessage());
            }
            $this->resetToMainMenu($session, $phone);
            return;
        }

        $this->apiService->sendTextMessage($phone, "Cancellation was aborted. Returning to main menu.");
        $this->resetToMainMenu($session, $phone);
    }
}
